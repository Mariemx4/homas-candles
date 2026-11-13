<?php

declare(strict_types=1);

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


    public function __construct(
        private readonly OrderProductionRepository $production,
        private readonly TransactionManager $transactions
    ) {
    }


    /** Active materials that may be used in a custom production recipe. */
    public function materials(): array
    {
        return $this->production->findActiveMaterials();
    }


    /**
     * Checks recipe lines submitted with a custom item.
     *
     * Blank rows are skipped. Each line needs an active material (used once) and a
     * positive quantity with up to three decimals; at most MAX_RECIPE_LINES lines.
     *
     * @param array<int, array{id: mixed}> $materials the materials() result
     * @return array{0: list<array{material: array, quantity: string}>, 1: ?string} [lines, error message]
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
     * Material cost of one unit made with these recipe lines, in millis (rounded half up once).
     *
     * @param list<array{material: array{unit_cost: mixed}, quantity: string}> $lines
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


    /** Saves an item's recipe lines; call it in the same transaction as the item insert. */
    public function insertRecipe(int $orderItemId, array $lines): void
    {
        foreach ($lines as $line) {
            $this->production->insertItemMaterial($orderItemId, $line['material'], $line['quantity']);
        }
    }

    /**
     * The product's current catalogue recipe (with the chosen scent's fragrance), in parseRecipe() line format.
     *
     * Orders store a copy of it so later catalogue changes do not affect them.
     */
    public function catalogueRecipe(int $productId, ?int $scentId = null): array
    {
        return $this->production->catalogueRecipe($productId, $scentId);
    }


    /**
     * Items of an order that cannot be produced yet, with the reason for each.
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
     * Refuses to start or finish production while any item lacks a recipe.
     *
     * @throws DomainException listing the items and what each is missing
     */
    public function assertReadyForProduction(int $orderId): void
    {
        $missing = $this->missingProductionData($orderId);
        if ($missing === []) {
            return;
        }
        $details = array_map(static fn (array $item): string => $item['name'] . ' (' . $item['reason'] . ')', $missing);
        throw new DomainException(
            'Production data is missing for: ' . implode(', ', $details)
            . '. Add a custom production recipe to each item on the order page before preparing it.'
        );
    }


    /**
     * Production data for the order page: each item's recipe source and lines, and
     * whether custom recipes can still be edited (before PREPARED and before any
     * material deduction).
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
     * Replaces the custom production recipe of one order item.
     *
     * Refused for catalogue items (their recipe was captured at sale) and once the
     * order has been prepared or its materials deducted.
     *
     * @return array{success: bool, errors: array<string, string>}
     */
    public function saveItemRecipe(int $orderId, int $orderItemId, mixed $rawLines): array
    {
        if ($orderId < 1 || $orderItemId < 1) {
            return ['success' => false, 'errors' => ['recipe' => 'The order item could not be found.']];
        }

        try {
            return $this->transactions->run(function () use ($orderId, $orderItemId, $rawLines): array {
                $item = $this->production->lockItemForRecipe($orderId, $orderItemId);
                if ($item === null) {
                    throw new DomainException('The order item could not be found.');
                }
                if (($item['recipe_source'] ?? null) === 'CATALOGUE') {
                    throw new DomainException('The catalogue recipe captured at sale cannot be changed. Configure future recipes in Catalogue.');
                }
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
                $accessoryCost = $this->production->customAccessoryCostMillis($orderItemId);
                $cost = $accessoryCost === null ? null : self::formatDecimal((self::parseDecimal((string)$cost) ?? 0) + $accessoryCost);
                $this->production->updateItemCost($orderItemId, $cost);

                return ['success' => true, 'errors' => [], 'orderNumber' => (string) $item['order_number']];
            });
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['recipe' => $exception->getMessage()]];
        }
    }


    /** A decimal with up to three places as integer thousandths, e.g. '1.25' => 1250; null if invalid. */
    public static function parseDecimal(string $value): ?int
    {
        if (preg_match('/^(0|[1-9][0-9]{0,8})(?:\.([0-9]{1,3}))?$/', $value, $matches) !== 1) {
            return null;
        }

        return ((int) $matches[1] * 1000) + (int) str_pad($matches[2] ?? '', 3, '0');
    }


    /** Thousandths as a three-decimal string, e.g. 1250 => '1.250'. */
    public static function formatDecimal(int $millis): string
    {
        return intdiv($millis, 1000) . '.' . str_pad((string) ($millis % 1000), 3, '0', STR_PAD_LEFT);
    }


    private function missingReason(array $row): ?string
    {
        // An explicit custom recipe always wins over the catalogue recipe.
        if ((bool) $row['has_custom_recipe']) {
            return null;
        }
        return 'no saved production recipe; confirm the historical or agreed custom recipe before production';
    }
}
