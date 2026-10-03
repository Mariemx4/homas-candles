<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Business service for Material Service; it contains application rules and coordinates related persistence work.
 *
 * DATA FLOW: Controllers call this layer. It validates and normalizes domain input, calls repositories or storage collaborators, and returns structured results without rendering HTML.
 */

namespace App\Services;

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

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly MaterialRepository $materials,
        private readonly MaterialMovementRepository $movements,
        private readonly MaterialStockRepository $stock,
        private readonly TransactionManager $transactions,
        private readonly ProductReadinessService $readiness
    ) {
    }

    /**
     * FUNCTION PURPOSE: Prepare a filtered and paginated collection from the supplied query options.
     *
     * @return array<string, mixed>
     */
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
            // URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
            'returnQuery' => http_build_query($state),
            'filtersAdjusted' => $normalized['adjusted'],
        ];
    }

    /**
     * FUNCTION PURPOSE: Load and assemble all data needed by one detail screen.
     *
     * @return array<string, mixed>|null
     */
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
     * FUNCTION PURPOSE: Validate and persist the supplied value.
     *
     * @return array{success: bool, errors: array<string, string>, id?: int}
     */
    public function save(?int $materialId, array $input): array
    {
        $referenced = $materialId !== null && $this->materials->isReferenced($materialId);
        [$core, $initialStock, $errors] = $this->validate($input, $materialId, $referenced);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        try {
            // TRANSACTION IDIOM: Run related writes as one unit; an exception rolls back every write in this closure.
            [$savedId, $notice] = $this->transactions->run(function () use ($materialId, $core, $initialStock, $referenced): array {
                if ($materialId === null) {
                    $id = $this->materials->insert($core);
                    if ($core['tracks_stock'] === 1 && $this->moneyToMillis($initialStock) > 0) {
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

                if ($referenced) {
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
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return ['success' => false, 'errors' => ['sku' => 'This SKU is already used by another material.']];
            }

            throw $exception;
        }

        return ['success' => true, 'errors' => [], 'id' => $savedId, 'notice' => $notice];
    }

    /**
     * FUNCTION PURPOSE: Switch active to the requested boolean state after validation.
     *
     * @return array{success: bool, errors: array<string, string>}
     */
    public function toggleActive(int $materialId, bool $active): array
    {
        $material = $this->materials->find($materialId);
        if ($material === null) {
            return ['success' => false, 'errors' => ['form' => 'The material could not be found.']];
        }
        $this->materials->updateEditableFields($materialId, [
            'name' => $material['name'], 'unit_cost' => $material['unit_cost'], 'is_active' => $active ? 1 : 0,
            'minimum_stock_level' => $material['minimum_stock_level'],
        ]);

        return ['success' => true, 'errors' => []];
    }

    /**
     * FUNCTION PURPOSE: Validate and record movement as a new event or movement.
     *
     * @return array{success: bool, errors: array<string, string>}
     */
    public function recordMovement(int $materialId, int $administratorId, string $movementType, string $rawQuantity, string $note): array
    {
        if (!in_array($movementType, self::MANUAL_MOVEMENT_TYPES, true)) {
            return ['success' => false, 'errors' => ['movement_type' => 'Select a valid movement type.']];
        }
        $note = trim($note);
        $quantityMillis = $this->parseMoney($rawQuantity);
        if ($quantityMillis === null || $quantityMillis === 0) {
            return ['success' => false, 'errors' => ['quantity_change' => 'Enter a non-zero quantity with up to three decimals.']];
        }
        if ($this->length($note) > 500) {
            return ['success' => false, 'errors' => ['movement_note' => 'The note must be 500 characters or fewer.']];
        }
        if (in_array($movementType, ['ADJUSTMENT_OUT', 'ADJUSTMENT_IN', 'DAMAGED_OR_WASTED'], true) && $note === '') {
            return ['success' => false, 'errors' => ['movement_note' => 'Adjustments and waste require a note explaining the change.']];
        }

        $signedMillis = in_array($movementType, self::OUTBOUND_TYPES, true) ? -abs($quantityMillis) : abs($quantityMillis);
        $signedQuantity = $this->millisToMoney($signedMillis);

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
        // COLLECTION IDIOM: Keep only array items for which the callback returns true.
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

    /**
     * FUNCTION PURPOSE: Check the supplied value against the application rules and return usable values or errors.
     *
     * @return array{0: array<string, mixed>, 1: string, 2: array<string, string>}
     */
    private function validate(array $input, ?int $materialId, bool $referenced): array
    {
        $errors = [];
        $string = static fn (string $key): string => isset($input[$key]) && is_string($input[$key]) ? trim($input[$key]) : '';

        $name = $string('name');
        if ($name === '' || $this->length($name) > 180) {
            $errors['name'] = 'Enter a material name of no more than 180 characters.';
        }

        $unitCostMillis = $this->parseMoney($string('unit_cost'));
        if ($unitCostMillis === null || $unitCostMillis < 0) {
            $errors['unit_cost'] = 'Enter a unit cost of zero or more, with up to three decimals.';
            $unitCostMillis = 0;
        }

        $isActive = ($input['is_active'] ?? '') === '1';

        $core = ['name' => $name, 'unit_cost' => $this->millisToMoney($unitCostMillis), 'is_active' => $isActive ? 1 : 0];
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
        $minimumMillis = $this->parseMoney($minimumRaw);
        if ($tracksStock && ($minimumMillis === null || $minimumMillis < 0 || str_starts_with($minimumRaw, '-'))) {
            $errors['minimum_stock_level'] = 'Enter a minimum stock level of zero or more, with up to three decimals.';
        }
        $core['minimum_stock_level'] = $tracksStock && $minimumMillis !== null
            ? $this->millisToMoney($minimumMillis) : null;

        if ($materialId === null || !$referenced) {
            // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
            $materialTypeId = filter_var($string('material_type_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($materialTypeId === false) {
                $errors['material_type_id'] = 'Select a material type.';
            }
            $sku = $string('sku');
            if ($sku === '' || $this->length($sku) > 100) {
                $errors['sku'] = 'Enter an SKU of no more than 100 characters.';
            } elseif ($this->materials->skuExists($sku, $materialId)) {
                $errors['sku'] = 'This SKU is already used by another material.';
            }
            $unit = $string('unit');
            if ($unit === '' || $this->length($unit) > 30) {
                $errors['unit'] = 'Enter a unit of no more than 30 characters.';
            }
            if ($tracksStock && $materialId === null) {
                $initialRaw = $string('initial_stock_quantity');
                if ($initialRaw !== '') {
                    $initialMillis = $this->parseMoney($initialRaw);
                    if ($initialMillis === null || $initialMillis < 0) {
                        $errors['initial_stock_quantity'] = 'Enter an opening stock quantity of zero or more.';
                    } else {
                        $initialStock = $this->millisToMoney($initialMillis);
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

    /**
     * FUNCTION PURPOSE: Validate and convert list query into a consistent internal shape.
     *
     * @return array<string, mixed>
     */
    private function normalizeListQuery(array $query): array
    {
        $adjusted = false;
        $search = trim($this->queryString($query, 'q', $adjusted));
        if (strlen($search) > 100) {
            $search = substr($search, 0, 100);
            $adjusted = true;
        }
        $typeText = $this->queryString($query, 'type', $adjusted);
        // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
        $type = $typeText === '' ? null : filter_var($typeText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($type === false) {
            $type = null;
            $adjusted = true;
        }
        $active = $this->queryString($query, 'active', $adjusted);
        if (!in_array($active, ['', '0', '1'], true)) {
            $active = '';
            $adjusted = true;
        }
        $tracksStock = $this->queryString($query, 'tracks_stock', $adjusted);
        if (!in_array($tracksStock, ['', '0', '1'], true)) {
            $tracksStock = '';
            $adjusted = true;
        }
        $stockStatus = $this->queryString($query, 'stock_status', $adjusted);
        if (!in_array($stockStatus, ['', 'low', 'unconfigured'], true)) {
            $stockStatus = '';
            $adjusted = true;
        }
        $sort = $this->queryString($query, 'sort', $adjusted);
        if ($sort === '') {
            $sort = 'name';
        } elseif (!in_array($sort, self::SORTS, true)) {
            $sort = 'name';
            $adjusted = true;
        }
        $direction = $this->queryString($query, 'direction', $adjusted);
        if (!in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }
        $pageText = $this->queryString($query, 'page', $adjusted);
        // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
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

    /**
     * FUNCTION PURPOSE: Validate and convert movement query into a consistent internal shape.
     *
     * @return array<string, mixed>
     */
    private function normalizeMovementQuery(array $query): array
    {
        $adjusted = false;
        $materialText = $this->queryString($query, 'material', $adjusted);
        // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
        $material = $materialText === '' ? null : filter_var($materialText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($material === false) {
            $material = null;
            $adjusted = true;
        }
        $type = strtoupper($this->queryString($query, 'type', $adjusted));
        $allTypes = array_merge(self::MANUAL_MOVEMENT_TYPES, ['ORDER_DEDUCTION', 'ORDER_RESTOCK']);
        if ($type !== '' && !in_array($type, $allTypes, true)) {
            $type = '';
            $adjusted = true;
        }
        $orderText = $this->queryString($query, 'order', $adjusted);
        // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
        $order = $orderText === '' ? null : filter_var($orderText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($order === false) {
            $order = null;
            $adjusted = true;
        }
        $administratorText = $this->queryString($query, 'administrator', $adjusted);
        // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
        $administrator = $administratorText === '' ? null : filter_var($administratorText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($administrator === false) {
            $administrator = null;
            $adjusted = true;
        }
        $dateFrom = $this->queryDate($query, 'date_from', $adjusted);
        $dateTo = $this->queryDate($query, 'date_to', $adjusted);
        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            $dateFrom = $dateTo = null;
            $adjusted = true;
        }
        $pageText = $this->queryString($query, 'page', $adjusted);
        // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
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

    /**
     * FUNCTION PURPOSE: Read and normalize the state query value.
     *
     * @return array<string, mixed>
     */
    private function queryState(array $normalized, int $page): array
    {
        $filters = $normalized['filters'];

        // COLLECTION IDIOM: Keep only array items for which the callback returns true.
        return array_filter([
            'q' => $filters['search'], 'type' => $filters['type'], 'active' => $filters['active'],
            'tracks_stock' => $filters['tracks_stock'], 'sort' => $normalized['sort'],
            'stock_status' => $filters['stock_status'],
            'direction' => $normalized['direction'], 'page' => $page,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * FUNCTION PURPOSE: Read and normalize the string query value.
     */
    private function queryString(array $query, string $key, bool &$adjusted): string
    {
        if (!array_key_exists($key, $query)) {
            return '';
        }
        if (!is_string($query[$key])) {
            $adjusted = true;

            return '';
        }

        return $query[$key];
    }

    /**
     * FUNCTION PURPOSE: Read and normalize the date query value.
     */
    private function queryDate(array $query, string $key, bool &$adjusted): ?string
    {
        $value = $this->queryString($query, $key, $adjusted);
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            $adjusted = true;

            return null;
        }

        return $value;
    }

    /**
     * FUNCTION PURPOSE: Parse money text without accepting unsupported formats.
     */
    private function parseMoney(string $value): ?int
    {
        // VALIDATION IDIOM: Test the complete string against a regular-expression format before accepting it.
        if (preg_match('/^(-?)(?:0|[1-9][0-9]{0,8})(?:\.([0-9]{1,3}))?$/', $value, $matches) !== 1) {
            return null;
        }
        $sign = $matches[1] === '-' ? -1 : 1;
        $wholeAndFraction = ltrim($value, '-');
        $whole = (int) explode('.', $wholeAndFraction)[0];

        return $sign * (($whole * 1000) + (int) str_pad($matches[2] ?? '', 3, '0'));
    }

    /**
     * FUNCTION PURPOSE: Convert to millis using integer thousandths to avoid floating-point errors.
     */
    private function moneyToMillis(string $amount): int
    {
        $millis = $this->parseMoney($amount);

        return $millis ?? 0;
    }

    /**
     * FUNCTION PURPOSE: Convert to money between integer thousandths and a money string.
     */
    private function millisToMoney(int $millis): string
    {
        $sign = $millis < 0 ? '-' : '';
        $millis = abs($millis);

        return $sign . intdiv($millis, 1000) . '.' . str_pad((string) ($millis % 1000), 3, '0', STR_PAD_LEFT);
    }

    /**
     * FUNCTION PURPOSE: Count characters in the supplied value safely for UTF-8 validation.
     */
    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
