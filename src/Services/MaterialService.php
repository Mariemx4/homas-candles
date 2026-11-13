<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\FormInput;
use App\Http\ListQuery;
use App\Database\TransactionManager;
use App\Repositories\MaterialMovementRepository;
use App\Repositories\MaterialRepository;
use App\Repositories\MaterialStockRepository;
use DomainException;

final class MaterialService
{
    public const MANUAL_MOVEMENT_TYPES = ['STOCK_IN', 'ADJUSTMENT_IN', 'ADJUSTMENT_OUT', 'DAMAGED_OR_WASTED'];
    private const OUTBOUND_TYPES = ['ADJUSTMENT_OUT', 'DAMAGED_OR_WASTED'];
    public const PER_PAGE = 20;
    public const SORTS = ['name', 'stock_quantity', 'unit_cost'];


    public function __construct(
        private readonly MaterialRepository $materials,
        private readonly MaterialMovementRepository $movements,
        private readonly MaterialStockRepository $stock,
        private readonly TransactionManager $transactions,
        private readonly ProductReadinessService $readiness
    ) {
    }


    public function list(array $query): array
    {
        $normalized = $this->normalizeListQuery($query);
        $filters = $normalized['filters'];
        $total = $this->materials->countForList($filters);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($normalized['page'], $totalPages);
        $rows = $total === 0 ? [] : $this->materials->findForList(
            $filters,
            $normalized['sort'],
            $normalized['direction'],
            ($page - 1) * self::PER_PAGE,
            self::PER_PAGE
        );
        $state = $this->queryState($normalized, $page);

        return [
            'materials' => $rows,
            'types' => $this->materials->findActiveTypes(),
            'filters' => $filters,
            'sort' => $normalized['sort'],
            'direction' => $normalized['direction'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $total,
            'totalPages' => $totalPages,
            'queryState' => $state,
            'returnQuery' => http_build_query($state),
            'filtersAdjusted' => $normalized['adjusted'],
        ];
    }


    public function detail(?int $materialId): ?array
    {
        if ($materialId === null) {
            return [
                'material' => null,
                'types' => $this->materials->findActiveTypes(),
                'movements' => [],
                'referenced' => false,
            ];
        }

        $material = $this->materials->find($materialId);
        if ($material === null) {
            return null;
        }

        return [
            'material' => $material,
            'types' => $this->materials->findActiveTypes(),
            'movements' => $this->movements->findForList(['material' => (string) $materialId], 0, 10),
            'referenced' => $this->materials->isReferenced($materialId),
        ];
    }


    /**
     * Creates or updates a material; a new tracked material can record opening stock.
     *
     * Saving reprices the catalogue and re-checks product readiness in the same transaction.
     *
     * @return array{success: bool, errors: array<string, string>, notice?: ?string}
     */
    public function save(?int $materialId, array $input): array
    {
        $referenced = $materialId !== null && $this->materials->isReferenced($materialId);
        [$core, $initialStock, $errors] = $this->validate($input, $materialId, $referenced);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        try {
            [$savedId, $notice] = $this->transactions->run(function () use ($materialId, $core, $initialStock, $referenced, $input): array {
                if ($materialId === null) {
                    $id = $this->materials->insert($core);
                    if ($core['tracks_stock'] === 1 && (Money::parseSignedMillis($initialStock) ?? 0) > 0) {
                        $this->stock->recordMovement(
                            $id,
                            'STOCK_IN',
                            $initialStock,
                            null,
                            null,
                            'Opening stock recorded at material creation.'
                        );
                    }

                    // A new material is in no recipe yet, so no price can change.
                    return [$id, null];
                }

                $current = $this->materials->lock($materialId);
                if ($current === null) {
                    throw new DomainException('The material could not be found.');
                }
                $referenced = $this->materials->isReferenced($materialId);
                if ($referenced) {
                    foreach (['unit','sku','tracks_stock','material_type_id'] as $identity) {
                        if ((string)$current[$identity] !== (string)($core[$identity] ?? (is_string($input[$identity] ?? null) ? $input[$identity] : $current[$identity]))) {
                            throw new DomainException('This material is used by recipes, stock or history. Its unit, SKU, stock identity and type cannot change.');
                        }
                    }
                    $this->materials->updateEditableFields($materialId, [
                        'name' => $core['name'], 'unit_cost' => $core['unit_cost'], 'is_active' => $core['is_active'],
                        'minimum_stock_level' => $core['minimum_stock_level'],
                    ]);
                } else {
                    $this->materials->updateAllFields($materialId, $core);
                }

                // WHAT: reprice the catalogue and re-check readiness in this same transaction.
                // WHY:  documented rule — "Changes to material costs automatically
                //       update current catalogue prices". Placed orders keep their
                //       frozen snapshots, so only current prices move. A cost edit
                //       (for example to zero) can leave products unpriceable; no
                //       product is edited directly here, so those return to draft
                //       and the admin is told which ones.
                return [$materialId, $this->readiness->settle()];
            });
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['form' => $exception->getMessage()]];
        } catch (\PDOException $exception) {
            if (in_array($exception->getCode(), ['23000', '23505'], true)) {
                return ['success' => false, 'errors' => ['sku' => 'This SKU is already used by another material.']];
            }

            throw $exception;
        }

        return ['success' => true, 'errors' => [], 'id' => $savedId, 'notice' => $notice];
    }


    /**
     * Activates or deactivates a material; products that rely on it return to draft if they become incomplete.
     *
     * @return array{success: bool, errors: array<string, string>, notice: ?string}
     */
    public function toggleActive(int $materialId, bool $active): array
    {
        $material = $this->materials->find($materialId);
        if ($material === null) {
            return ['success' => false, 'errors' => ['form' => 'The material could not be found.']];
        }
        $applied = $this->readiness->applyChange(function () use ($materialId, $active): void {
            $material = $this->materials->lock($materialId);
            if (!$material) {
                throw new DomainException('The material could not be found.');
            }
            $this->materials->updateEditableFields($materialId, [
                'name' => $material['name'],'unit_cost' => $material['unit_cost'],'is_active' => $active ? 1 : 0,'minimum_stock_level' => $material['minimum_stock_level'],
            ]);
        });
        return ['success' => true,'errors' => [],'notice' => $applied['notice']];
    }


    /**
     * Records a manual stock movement (delivery in, adjustment or waste).
     *
     * Outbound types are stored as negative quantities. Adjustments and waste
     * need a note.
     *
     * @param string $rawQuantity quantity with up to three decimals
     * @return array{success: bool, errors: array<string, string>}
     */
    public function recordMovement(int $materialId, int $administratorId, string $movementType, string $rawQuantity, string $note): array
    {
        if (!in_array($movementType, self::MANUAL_MOVEMENT_TYPES, true)) {
            return ['success' => false, 'errors' => ['movement_type' => 'Select a valid movement type.']];
        }
        $note = trim($note);
        $quantityMillis = Money::parseSignedMillis($rawQuantity);
        if ($quantityMillis === null || $quantityMillis === 0) {
            return ['success' => false, 'errors' => ['quantity_change' => 'Enter a non-zero quantity with up to three decimals.']];
        }
        if (Text::length($note) > 500) {
            return ['success' => false, 'errors' => ['movement_note' => 'The note must be 500 characters or fewer.']];
        }
        if (in_array($movementType, ['ADJUSTMENT_OUT', 'ADJUSTMENT_IN', 'DAMAGED_OR_WASTED'], true) && $note === '') {
            return ['success' => false, 'errors' => ['movement_note' => 'Adjustments and waste require a note explaining the change.']];
        }

        $signedMillis = in_array($movementType, self::OUTBOUND_TYPES, true) ? -abs($quantityMillis) : abs($quantityMillis);
        $signedQuantity = Money::formatSignedMillis($signedMillis);

        try {
            $this->stock->recordMovement($materialId, $movementType, $signedQuantity, null, $administratorId, $note === '' ? null : $note);
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['form' => $exception->getMessage()]];
        }

        return ['success' => true, 'errors' => []];
    }

    /**
     * @return array<string, mixed>
     */
    public function movementHistory(array $query): array
    {
        $normalized = $this->normalizeMovementQuery($query);
        $filters = $normalized['filters'];
        $total = $this->movements->countForList($filters);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($normalized['page'], $totalPages);
        $rows = $total === 0 ? [] : $this->movements->findForList($filters, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        $state = array_filter($filters + ['page' => $page], static fn (mixed $value): bool => $value !== null && $value !== '');

        return [
            'movements' => $rows,
            'filters' => $filters,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $total,
            'totalPages' => $totalPages,
            'queryState' => $state,
            'filtersAdjusted' => $normalized['adjusted'],
        ];
    }


    private function validate(array $input, ?int $materialId, bool $referenced): array
    {
        $errors = [];
        $string = static fn (string $key): string => FormInput::trimmed($input, $key);

        $name = $string('name');
        if ($name === '' || Text::length($name) > 180) {
            $errors['name'] = 'Enter a material name of no more than 180 characters.';
        }

        $unitCostMillis = Money::parseSignedMillis($string('unit_cost'));
        if ($unitCostMillis === null || $unitCostMillis < 0) {
            $errors['unit_cost'] = 'Enter a unit cost of zero or more, with up to three decimals.';
            $unitCostMillis = 0;
        }

        $isActive = ($input['is_active'] ?? '') === '1';

        $core = ['name' => $name, 'unit_cost' => Money::formatSignedMillis($unitCostMillis), 'is_active' => $isActive ? 1 : 0];
        $initialStock = '0.000';

        // Locked tracking is read from the saved record, never from a tampered form.
        $existing = $materialId === null ? null : $this->materials->find($materialId);
        if ($materialId !== null && $existing === null) {
            $errors['form'] = 'The material could not be found.';
        }
        $tracksStock = $referenced
            ? (int) ($existing['tracks_stock'] ?? 0) === 1
            : ($input['tracks_stock'] ?? '') === '1';
        $minimumRaw = $string('minimum_stock_level');
        $minimumMillis = Money::parseSignedMillis($minimumRaw);
        if ($tracksStock && ($minimumMillis === null || $minimumMillis < 0 || str_starts_with($minimumRaw, '-'))) {
            $errors['minimum_stock_level'] = 'Enter a minimum stock level of zero or more, with up to three decimals.';
        }
        $core['minimum_stock_level'] = $tracksStock && $minimumMillis !== null
            ? Money::formatSignedMillis($minimumMillis) : null;

        if ($materialId === null || !$referenced) {
            $materialTypeId = filter_var($string('material_type_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($materialTypeId === false) {
                $errors['material_type_id'] = 'Select a material type.';
            }
            $sku = $string('sku');
            if ($sku === '' || Text::length($sku) > 100) {
                $errors['sku'] = 'Enter an SKU of no more than 100 characters.';
            } elseif ($this->materials->skuExists($sku, $materialId)) {
                $errors['sku'] = 'This SKU is already used by another material.';
            }
            $unit = $string('unit');
            if ($unit === '' || Text::length($unit) > 30) {
                $errors['unit'] = 'Enter a unit of no more than 30 characters.';
            }
            if ($tracksStock && $materialId === null) {
                $initialRaw = $string('initial_stock_quantity');
                if ($initialRaw !== '') {
                    $initialMillis = Money::parseSignedMillis($initialRaw);
                    if ($initialMillis === null || $initialMillis < 0) {
                        $errors['initial_stock_quantity'] = 'Enter an opening stock quantity of zero or more.';
                    } else {
                        $initialStock = Money::formatSignedMillis($initialMillis);
                    }
                }
            }

            $core['material_type_id'] = $materialTypeId === false ? null : $materialTypeId;
            $core['sku'] = $sku;
            $core['unit'] = $unit;
            $core['tracks_stock'] = $tracksStock ? 1 : 0;
            $core['stock_quantity'] = $tracksStock ? '0.000' : null;
        }

        return [$core, $initialStock, $errors];
    }


    private function normalizeListQuery(array $query): array
    {
        $adjusted = false;
        $search = trim(ListQuery::string($query, 'q', $adjusted));
        if (strlen($search) > 100) {
            $search = substr($search, 0, 100);
            $adjusted = true;
        }
        $typeText = ListQuery::string($query, 'type', $adjusted);
        $type = $typeText === '' ? null : filter_var($typeText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($type === false) {
            $type = null;
            $adjusted = true;
        }
        $active = ListQuery::string($query, 'active', $adjusted);
        if (!in_array($active, ['', '0', '1'], true)) {
            $active = '';
            $adjusted = true;
        }
        $tracksStock = ListQuery::string($query, 'tracks_stock', $adjusted);
        if (!in_array($tracksStock, ['', '0', '1'], true)) {
            $tracksStock = '';
            $adjusted = true;
        }
        $stockStatus = ListQuery::string($query, 'stock_status', $adjusted);
        if (!in_array($stockStatus, ['', 'low', 'unconfigured'], true)) {
            $stockStatus = '';
            $adjusted = true;
        }
        $sort = ListQuery::string($query, 'sort', $adjusted);
        if ($sort === '') {
            $sort = 'name';
        } elseif (!in_array($sort, self::SORTS, true)) {
            $sort = 'name';
            $adjusted = true;
        }
        $direction = ListQuery::string($query, 'direction', $adjusted);
        if (!in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }
        $pageText = ListQuery::string($query, 'page', $adjusted);
        $page = $pageText === '' ? 1 : filter_var($pageText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        if ($page === false) {
            $page = 1;
            $adjusted = true;
        }

        return [
            'filters' => [
                'search' => $search === '' ? null : $search,
                'type' => $type === null ? null : (string) $type,
                'active' => $active === '' ? null : $active,
                'tracks_stock' => $tracksStock === '' ? null : $tracksStock,
                'stock_status' => $stockStatus === '' ? null : $stockStatus,
            ],
            'sort' => $sort,
            'direction' => $direction,
            'page' => (int) $page,
            'adjusted' => $adjusted,
        ];
    }


    private function normalizeMovementQuery(array $query): array
    {
        $adjusted = false;
        $materialText = ListQuery::string($query, 'material', $adjusted);
        $material = $materialText === '' ? null : filter_var($materialText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($material === false) {
            $material = null;
            $adjusted = true;
        }
        $type = strtoupper(ListQuery::string($query, 'type', $adjusted));
        $allTypes = array_merge(self::MANUAL_MOVEMENT_TYPES, ['ORDER_DEDUCTION', 'ORDER_RESTOCK']);
        if ($type !== '' && !in_array($type, $allTypes, true)) {
            $type = '';
            $adjusted = true;
        }
        $orderText = ListQuery::string($query, 'order', $adjusted);
        $order = $orderText === '' ? null : filter_var($orderText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($order === false) {
            $order = null;
            $adjusted = true;
        }
        $administratorText = ListQuery::string($query, 'administrator', $adjusted);
        $administrator = $administratorText === '' ? null : filter_var($administratorText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($administrator === false) {
            $administrator = null;
            $adjusted = true;
        }
        $dateFrom = ListQuery::date($query, 'date_from', $adjusted);
        $dateTo = ListQuery::date($query, 'date_to', $adjusted);
        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            $dateFrom = $dateTo = null;
            $adjusted = true;
        }
        $pageText = ListQuery::string($query, 'page', $adjusted);
        $page = $pageText === '' ? 1 : filter_var($pageText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        if ($page === false) {
            $page = 1;
            $adjusted = true;
        }

        return [
            'filters' => [
                'material' => $material === null ? null : (string) $material,
                'type' => $type === '' ? null : $type,
                'order' => $order === null ? null : (string) $order,
                'administrator' => $administrator === null ? null : (string) $administrator,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'page' => (int) $page,
            'adjusted' => $adjusted,
        ];
    }


    private function queryState(array $normalized, int $page): array
    {
        $filters = $normalized['filters'];

        return array_filter([
            'q' => $filters['search'], 'type' => $filters['type'], 'active' => $filters['active'],
            'tracks_stock' => $filters['tracks_stock'], 'sort' => $normalized['sort'],
            'stock_status' => $filters['stock_status'],
            'direction' => $normalized['direction'], 'page' => $page,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }







}
