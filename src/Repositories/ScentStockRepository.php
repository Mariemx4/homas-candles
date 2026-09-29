<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Persistence repository for Scent Stock Repository; it owns the SQL used to read and write this feature's data.
 *
 * DATA FLOW: ScentAvailabilityService calls this layer to learn how much
 * fragrance a product needs per unit, which fragrance material each scent
 * uses (with its stock), and how much of that material open orders have
 * already committed. It never changes stock; the PREPARED deduction does that
 * through MaterialStockRepository.
 *
 * FRAGRANCE MODEL
 *   A scent is linked to one fragrance material (scents.fragrance_material_id,
 *   migration 001). A product's ACTUAL recipe records its fragrance as a line
 *   whose material is one of those fragrance materials (for example
 *   "Vanilla fragrance oil, 8 ml"). That line is the product's scent slot:
 *   its quantity is the fragrance needed per unit, and the order's chosen
 *   scent supplies the actual material. A set needs the fragrance of its own
 *   recipe plus each component's × component quantity, all in the set's one
 *   chosen scent (docs/database-requirements.md).
 */

namespace App\Repositories;

use PDO;

final class ScentStockRepository
{
    // Orders whose fragrance is promised but not yet deducted (PREPARED deducts).
    private const COMMITTING_STATUSES = ['REQUESTED', 'CONFIRMED', 'PREPARING'];

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * FUNCTION PURPOSE: Calculate the fragrance quantity one unit of each product needs, in thousandths.
     *
     * @param list<int> $productIds
     * @return array<int, int> Product ID => fragrance per unit in thousandths (0 when the recipe has no scent slot).
     */
    public function fragrancePerUnit(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }
        [$ownPlaceholders, $ownParameters] = $this->idPlaceholders($productIds, 'own_');
        [$setPlaceholders, $setParameters] = $this->idPlaceholders($productIds, 'set_');

        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT recipe.product_id AS product_id, recipe.quantity_required AS quantity, 1 AS multiplier
             FROM product_materials AS recipe
             WHERE recipe.product_id IN ({$ownPlaceholders}) AND recipe.recipe_type = 'ACTUAL'
               AND recipe.material_id IN (SELECT fragrance_material_id FROM scents WHERE fragrance_material_id IS NOT NULL)

             UNION ALL

             SELECT components.set_product_id, component_recipe.quantity_required, components.quantity
             FROM set_components AS components
             INNER JOIN product_materials AS component_recipe
               ON component_recipe.product_id = components.component_product_id AND component_recipe.recipe_type = 'ACTUAL'
             WHERE components.set_product_id IN ({$setPlaceholders})
               AND component_recipe.material_id IN (SELECT fragrance_material_id FROM scents WHERE fragrance_material_id IS NOT NULL)"
        );
        $statement->execute($ownParameters + $setParameters);

        $perUnit = array_fill_keys($productIds, 0);
        foreach ($statement->fetchAll() as $row) {
            $perUnit[(int) $row['product_id']] += self::toMillis((string) $row['quantity']) * (int) $row['multiplier'];
        }

        return $perUnit;
    }

    /**
     * FUNCTION PURPOSE: Fetch the fragrance material behind each scent, optionally locking the material rows.
     *
     * WHY: with $lock the material rows stay locked until the caller's
     *      transaction ends, so two orders cannot both use the last millilitres.
     *
     * @param list<int> $scentIds
     * @return array<int, array{material_id: ?int, material_active: bool, tracks_stock: bool, stock_millis: ?int, scent_name: string, unit: ?string}>
     */
    public function scentMaterials(array $scentIds, bool $lock = false): array
    {
        $scentIds = array_values(array_unique(array_map('intval', $scentIds)));
        if ($scentIds === []) {
            return [];
        }
        [$placeholders, $parameters] = $this->idPlaceholders($scentIds, 'scent_');
        $statement = $this->connection->prepare(
            "SELECT scents.id AS scent_id, scents.fragrance_material_id, materials.is_active, materials.tracks_stock,
                    materials.stock_quantity, materials.unit, COALESCE(scent_text.name, scents.slug) AS scent_name
             FROM scents
             LEFT JOIN materials ON materials.id = scents.fragrance_material_id
             LEFT JOIN scent_translations AS scent_text ON scent_text.scent_id = scents.id AND scent_text.language_code = 'en'
             WHERE scents.id IN ({$placeholders})
             ORDER BY scents.fragrance_material_id ASC"
        );
        $statement->execute($parameters);
        $rows = $statement->fetchAll();

        if ($lock) {
            // Lock in material-ID order so concurrent orders cannot deadlock.
            $materialIds = array_values(array_unique(array_filter(array_map(
                static fn (array $row): int => (int) $row['fragrance_material_id'],
                $rows
            ))));
            sort($materialIds);
            if ($materialIds !== []) {
                [$lockPlaceholders, $lockParameters] = $this->idPlaceholders($materialIds, 'lock_');
                $locked = $this->connection->prepare(
                    "SELECT id, stock_quantity FROM materials WHERE id IN ({$lockPlaceholders}) ORDER BY id FOR UPDATE"
                );
                $locked->execute($lockParameters);
                // Re-read the stock under the lock; the first read was unlocked.
                $current = [];
                foreach ($locked->fetchAll() as $row) {
                    $current[(int) $row['id']] = $row['stock_quantity'];
                }
                foreach ($rows as &$row) {
                    if ($row['fragrance_material_id'] !== null && array_key_exists((int) $row['fragrance_material_id'], $current)) {
                        $row['stock_quantity'] = $current[(int) $row['fragrance_material_id']];
                    }
                }
                unset($row);
            }
        }

        $scents = [];
        foreach ($rows as $row) {
            $scents[(int) $row['scent_id']] = [
                'material_id' => $row['fragrance_material_id'] === null ? null : (int) $row['fragrance_material_id'],
                'material_active' => (int) ($row['is_active'] ?? 0) === 1,
                'tracks_stock' => (int) ($row['tracks_stock'] ?? 0) === 1,
                'stock_millis' => $row['stock_quantity'] === null ? null : self::toMillis((string) $row['stock_quantity']),
                'scent_name' => (string) $row['scent_name'],
                'unit' => $row['unit'] === null ? null : (string) $row['unit'],
            ];
        }

        return $scents;
    }

    /**
     * FUNCTION PURPOSE: List the open order lines that have promised fragrance from the given materials.
     *
     * WHAT: items of orders not in Trash, still REQUESTED/CONFIRMED/PREPARING,
     *       with no material deduction yet, whose chosen scent uses one of these
     *       materials. Items with an explicit custom recipe (migration 017) are
     *       made from that recipe instead, so they are left out.
     *
     * @param list<int> $materialIds
     * @return list<array{product_id: int, material_id: int, quantity: int}>
     */
    public function committedLines(array $materialIds, ?int $excludeOrderId = null): array
    {
        if ($materialIds === []) {
            return [];
        }
        [$placeholders, $parameters] = $this->idPlaceholders($materialIds, 'material_');
        $statuses = "'" . implode("', '", self::COMMITTING_STATUSES) . "'";
        $statement = $this->connection->prepare(
            "SELECT items.product_id, scents.fragrance_material_id AS material_id, items.quantity
             FROM order_items AS items
             INNER JOIN orders ON orders.id = items.order_id
             INNER JOIN scents ON scents.id = items.selected_scent_id
             WHERE scents.fragrance_material_id IN ({$placeholders})
               AND items.product_id IS NOT NULL
               AND orders.deleted_at IS NULL
               AND orders.order_status IN ({$statuses})
               AND orders.id <> :exclude_order_id
               AND NOT EXISTS (
                   SELECT 1 FROM material_stock_movements AS movements
                   WHERE movements.order_id = orders.id AND movements.movement_type = 'ORDER_DEDUCTION'
               )
               AND NOT EXISTS (SELECT 1 FROM order_item_materials AS custom WHERE custom.order_item_id = items.id)"
        );
        $statement->execute($parameters + ['exclude_order_id' => $excludeOrderId ?? 0]);

        // COLLECTION IDIOM: Transform every array item and return a new array without changing the original one.
        return array_map(static fn (array $row): array => [
            'product_id' => (int) $row['product_id'],
            'material_id' => (int) $row['material_id'],
            'quantity' => (int) $row['quantity'],
        ], $statement->fetchAll());
    }

    /**
     * FUNCTION PURPOSE: Convert a DECIMAL(12,3) string into integer thousandths.
     */
    public static function toMillis(string $value): int
    {
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-')), 2, '');
        $millis = ((int) $whole * 1000) + (int) str_pad(substr($fraction, 0, 3), 3, '0');

        return $negative ? -$millis : $millis;
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `idPlaceholders` (id placeholders) and return its result.
     *
     * @param list<int> $ids
     * @return array{0: string, 1: array<string, int>}
     */
    private function idPlaceholders(array $ids, string $prefix): array
    {
        $names = [];
        $parameters = [];
        foreach (array_values($ids) as $index => $id) {
            $name = $prefix . $index;
            $names[] = ':' . $name;
            $parameters[$name] = (int) $id;
        }

        return [implode(', ', $names), $parameters];
    }
}
