<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Business service for Order Production Service; it decides whether order items carry the data production needs and manages their explicit custom recipes.
 *
 * DATA FLOW: ManualOrderService uses it to validate recipe lines while an
 * order is created; OrderStatusService asks it to block PREPARING/PREPARED
 * when data is missing; the order detail page reads each item's production
 * source and saves custom recipes through it. Persistence goes through
 * OrderProductionRepository.
 */

namespace App\Services;

use App\Database\TransactionManager;
use App\Repositories\OrderProductionRepository;
use DomainException;

final class OrderProductionService
{
    public const MAX_RECIPE_LINES = 20;

    // A custom recipe may change only before production is recorded; migration
    // 017 triggers enforce the same rule in the database.
    public const EDITABLE_STATUSES = ['REQUESTED', 'CONFIRMED', 'PREPARING'];

    // Statuses that need complete production data: PREPARING starts making the
    // items, PREPARED deducts their materials.
    public const PRODUCTION_STATUSES = ['PREPARING', 'PREPARED'];

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly OrderProductionRepository $production,
        private readonly TransactionManager $transactions
    ) {
    }

    /**
     * FUNCTION PURPOSE: Expose the active materials for recipe forms and validation.
     *
     * @return array<int, array{id: int, sku: string, name: string, unit: string, unit_cost: string}>
     */
    public function materials(): array
    {
        return $this->production->findActiveMaterials();
    }

    /**
     * FUNCTION PURPOSE: Validate submitted recipe lines against the active materials.
     *
     * WHAT: skips completely blank rows, then requires an active material, a
     *       quantity greater than zero with up to three decimals, and no
     *       material listed twice.
     * WHY:  shared by manual-order creation and the order-page recipe form, so
     *       both accept exactly the same recipes.
     *
     * @param array<int, array{id: int, name: string, unit: string, unit_cost: string}> $materials
     * @return array{0: list<array{material: array, quantity: string}>, 1: ?string} The lines and an error message (null when valid).
     */
    public function parseRecipe(mixed $rawLines, array $materials): array
    {
        if ($rawLines === null || $rawLines === '') {
            return [[], null];
        }
        if (!is_array($rawLines)) {
            return [[], 'The production recipe is invalid.'];
        }

        $lines = [];
        $seen = [];
        foreach ($rawLines as $rawLine) {
            if (!is_array($rawLine)) {
                return [[], 'The production recipe is invalid.'];
            }
            $materialText = isset($rawLine['material_id']) && is_string($rawLine['material_id']) ? trim($rawLine['material_id']) : '';
            $quantityText = isset($rawLine['quantity']) && is_string($rawLine['quantity']) ? trim($rawLine['quantity']) : '';
            if ($materialText === '' && $quantityText === '') {
                // An unused blank row of the form, not an error.
                continue;
            }
            // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
            $materialId = filter_var($materialText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($materialId === false || !isset($materials[$materialId])) {
                return [[], 'Every recipe line needs an active material.'];
            }
            $quantityMillis = self::parseDecimal($quantityText);
            if ($quantityMillis === null || $quantityMillis <= 0) {
                return [[], 'Every recipe line needs a quantity greater than zero with up to three decimals.'];
            }
            if (isset($seen[$materialId])) {
                return [[], 'List each material only once in a recipe.'];
            }
            $seen[$materialId] = true;
            $lines[] = ['material' => $materials[$materialId], 'quantity' => self::formatDecimal($quantityMillis)];
        }
        if (count($lines) > self::MAX_RECIPE_LINES) {
            return [[], 'A production recipe can have at most ' . self::MAX_RECIPE_LINES . ' materials.'];
        }

        return [$lines, null];
    }

    /**
     * FUNCTION PURPOSE: Calculate the per-unit cost of a custom recipe in integer thousandths.
     *
     * WHAT: Σ quantity × unit cost, rounded once on the total (half up).
     * WHY:  matches PricingService, which rounds recipe costs like
     *       ROUND(SUM(...), 3), so a custom item and the same catalogue recipe
     *       record the same cost.
     *
     * @param list<array{material: array, quantity: string}> $lines
     */
    public function recipeCostMillis(array $lines): int
    {
        $total = 0;
        foreach ($lines as $line) {
            // thousandths × thousandths = millionths; rounded once below.
            $total += (int) self::parseDecimal($line['quantity']) * (int) self::parseDecimal((string) $line['material']['unit_cost']);
        }

        return intdiv($total + 500, 1000);
    }

    /**
     * FUNCTION PURPOSE: Store the custom recipe lines of a newly inserted order item.
     *
     * WHY: called by ManualOrderService inside its creation transaction.
     *
     * @param list<array{material: array, quantity: string}> $lines
     */
    public function insertRecipe(int $orderItemId, array $lines): void
    {
        foreach ($lines as $line) {
            $this->production->insertItemMaterial($orderItemId, $line['material'], $line['quantity']);
        }
    }

    public function catalogueRecipe(int $productId, ?int $scentId = null): array
    {
        return $this->production->catalogueRecipe($productId, $scentId);
    }

    /**
     * FUNCTION PURPOSE: List the items of one order that lack the data production needs.
     *
     * @return list<array{item_id: int, name: string, reason: string}>
     */
    public function missingProductionData(int $orderId): array
    {
        $missing = [];
        foreach ($this->production->findItemProductionData([$orderId]) as $row) {
            $reason = $this->missingReason($row);
            if ($reason !== null) {
                $missing[] = ['item_id' => (int) $row['id'], 'name' => (string) $row['product_name_snapshot'], 'reason' => $reason];
            }
        }

        return $missing;
    }

    /**
     * FUNCTION PURPOSE: Refuse to start or finish production while any item lacks production data.
     *
     * WHY: without a recipe the PREPARED deduction silently skips the item, so
     *      stock and cost records would be wrong. OrderStatusService calls this
     *      inside its transaction, so the refusal rolls back every step.
     *
     * @throws DomainException naming each incomplete item and why.
     */
    public function assertReadyForProduction(int $orderId): void
    {
        $missing = $this->missingProductionData($orderId);
        if ($missing === []) {
            return;
        }
        // COLLECTION IDIOM: Transform every array item and return a new array without changing the original one.
        $details = array_map(static fn (array $item): string => $item['name'] . ' (' . $item['reason'] . ')', $missing);
        throw new DomainException(
            'Production data is missing for: ' . implode(', ', $details)
            . '. Add a custom production recipe to each item on the order page before preparing it.'
        );
    }

    /**
     * FUNCTION PURPOSE: Describe how each item of one order will be produced, for the order page.
     *
     * @return array{items: array<int, array{source: string, reason: ?string, lines: list<array<string, mixed>>}>, editable: bool, missingCount: int, materials: array<int, array<string, mixed>>}
     */
    public function orderProduction(int $orderId, string $orderStatus): array
    {
        $lines = $this->production->findItemMaterials($orderId);
        $items = [];
        $missingCount = 0;
        foreach ($this->production->findItemProductionData([$orderId]) as $row) {
            $reason = $this->missingReason($row);
            $missingCount += $reason === null ? 0 : 1;
            $source = match (true) {
                ($row['recipe_source'] ?? null) === 'CATALOGUE' => 'CATALOGUE',
                (bool) $row['has_custom_recipe'] => 'CUSTOM',
                $reason !== null => 'MISSING',
                (bool) $row['is_set'] => 'SET',
                default => 'CATALOGUE',
            };
            $items[(int) $row['id']] = ['source' => $source, 'reason' => $reason, 'snapshot' => ($row['recipe_source'] ?? null) === 'CATALOGUE', 'lines' => $lines[(int) $row['id']] ?? []];
        }
        $editable = in_array($orderStatus, self::EDITABLE_STATUSES, true) && !$this->production->hasOrderDeduction($orderId);

        return [
            'items' => $items,
            'editable' => $editable,
            'missingCount' => $missingCount,
            'materials' => $editable ? $this->materials() : [],
        ];
    }

    /**
     * FUNCTION PURPOSE: Replace the custom production recipe of one order item.
     *
     * WHAT: legacy and one-off items must keep at least one saved recipe line.
     *       Sale-time catalogue snapshots are immutable. The cost snapshot
     *       follows a permitted explicit custom recipe correction.
     * WHY:  this is how legacy manual items, converted custom requests and
     *       customised catalogue items get the data that preparation requires.
     *
     * @return array{success: bool, errors: array<string, string>, orderNumber?: string}
     */
    public function saveItemRecipe(int $orderId, int $orderItemId, mixed $rawLines): array
    {
        if ($orderId < 1 || $orderItemId < 1) {
            return ['success' => false, 'errors' => ['recipe' => 'The order item could not be found.']];
        }

        try {
            // TRANSACTION IDIOM: Run related writes as one unit; an exception rolls back every write in this closure.
            return $this->transactions->run(function () use ($orderId, $orderItemId, $rawLines): array {
                $item = $this->production->lockItemForRecipe($orderId, $orderItemId);
                if ($item === null) {
                    throw new DomainException('The order item could not be found.');
                }
                if (($item['recipe_source'] ?? null) === 'CATALOGUE') throw new DomainException('The catalogue recipe captured at sale cannot be changed. Configure future recipes in Catalogue.');
                if (!in_array((string) $item['order_status'], self::EDITABLE_STATUSES, true) || (bool) $item['has_deduction']) {
                    throw new DomainException('This order has been prepared; its production recipe is part of the material record and cannot change.');
                }
                [$lines, $error] = $this->parseRecipe($rawLines, $this->materials());
                if ($error !== null) {
                    throw new DomainException($error);
                }
                if ($lines === []) {
                    throw new DomainException('A production item needs at least one saved recipe line.');
                }

                $this->production->deleteItemMaterials($orderItemId);
                $this->insertRecipe($orderItemId, $lines);
                $cost = $lines !== []
                    ? self::formatDecimal($this->recipeCostMillis($lines))
                    : $this->production->findProductCost((int) $item['product_id']);
                $this->production->updateItemCost($orderItemId, $cost);

                return ['success' => true, 'errors' => [], 'orderNumber' => (string) $item['order_number']];
            });
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['recipe' => $exception->getMessage()]];
        }
    }

    /**
     * FUNCTION PURPOSE: Parse a non-negative decimal with up to three places into integer thousandths.
     */
    public static function parseDecimal(string $value): ?int
    {
        // VALIDATION IDIOM: Test the complete string against a regular-expression format before accepting it.
        if (preg_match('/^(0|[1-9][0-9]{0,8})(?:\.([0-9]{1,3}))?$/', $value, $matches) !== 1) {
            return null;
        }

        return ((int) $matches[1] * 1000) + (int) str_pad($matches[2] ?? '', 3, '0');
    }

    /**
     * FUNCTION PURPOSE: Format integer thousandths as a DECIMAL(12,3) string.
     */
    public static function formatDecimal(int $millis): string
    {
        return intdiv($millis, 1000) . '.' . str_pad((string) ($millis % 1000), 3, '0', STR_PAD_LEFT);
    }

    /**
     * FUNCTION PURPOSE: Explain why one item cannot be produced, or return null when it can.
     *
     * @param array<string, mixed> $row A row from OrderProductionRepository::findItemProductionData().
     */
    private function missingReason(array $row): ?string
    {
        // An explicit custom recipe always wins over the catalogue recipe.
        if ((bool) $row['has_custom_recipe']) {
            return null;
        }
        return 'no saved production recipe; confirm the historical or agreed custom recipe before production';
    }
}
