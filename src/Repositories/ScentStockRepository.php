<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ScentStockRepository
{
    // Orders whose fragrance is promised but not yet deducted (PREPARED deducts).
    private const COMMITTING_STATUSES = ['REQUESTED', 'CONFIRMED', 'PREPARING'];


    public function __construct(private readonly PDO $connection)
    {
    }


    public function fragrancePerUnit(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }
        [$ownPlaceholders, $ownParameters] = SqlFragments::idPlaceholders($productIds, 'own_');
        [$setPlaceholders, $setParameters] = SqlFragments::idPlaceholders($productIds, 'set_');
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


    public function scentMaterials(array $scentIds, bool $lock = false): array
    {
        $scentIds = array_values(array_unique(array_map('intval', $scentIds)));
        if ($scentIds === []) {
            return [];
        }
        [$placeholders, $parameters] = SqlFragments::idPlaceholders($scentIds, 'scent_');
        $statement = $this->connection->prepare(
            "SELECT scents.id AS scent_id, scents.fragrance_material_id, scents.is_active AS scent_active,
                    materials.is_active, materials.tracks_stock,
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
                [$lockPlaceholders, $lockParameters] = SqlFragments::idPlaceholders($materialIds, 'lock_');
                $locked = $this->connection->prepare(
                    "SELECT id, stock_quantity, is_active, tracks_stock, unit
                     FROM materials WHERE id IN ({$lockPlaceholders}) ORDER BY id FOR UPDATE"
                );
                $locked->execute($lockParameters);
                // Re-read the stock under the lock; the first read was unlocked.
                $current = [];
                foreach ($locked->fetchAll() as $row) {
                    $current[(int) $row['id']] = $row;
                }
                foreach ($rows as &$row) {
                    if ($row['fragrance_material_id'] !== null && array_key_exists((int) $row['fragrance_material_id'], $current)) {
                        $lockedMaterial = $current[(int) $row['fragrance_material_id']];
                        $row['stock_quantity'] = $lockedMaterial['stock_quantity'];
                        $row['is_active'] = $lockedMaterial['is_active'];
                        $row['tracks_stock'] = $lockedMaterial['tracks_stock'];
                        $row['unit'] = $lockedMaterial['unit'];
                    }
                }
                unset($row);
            }
        }

        $scents = [];
        foreach ($rows as $row) {
            $scents[(int) $row['scent_id']] = [
                'material_id' => $row['fragrance_material_id'] === null ? null : (int) $row['fragrance_material_id'],
                'scent_active' => (int) $row['scent_active'] === 1,
                'material_active' => (int) ($row['is_active'] ?? 0) === 1,
                'tracks_stock' => (int) ($row['tracks_stock'] ?? 0) === 1,
                'stock_millis' => $row['stock_quantity'] === null ? null : self::toMillis((string) $row['stock_quantity']),
                'scent_name' => (string) $row['scent_name'],
                'unit' => $row['unit'] === null ? null : (string) $row['unit'],
            ];
        }

        return $scents;
    }


    public function committedLines(array $materialIds, ?int $excludeOrderId = null): array
    {
        if ($materialIds === []) {
            return [];
        }
        [$placeholders, $parameters] = SqlFragments::idPlaceholders($materialIds, 'material_');
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

        return array_map(static fn (array $row): array => [
            'product_id' => (int) $row['product_id'],
            'material_id' => (int) $row['material_id'],
            'quantity' => (int) $row['quantity'],
        ], $statement->fetchAll());
    }


    public function committedSnapshotQuantities(array $materialIds, ?int $excludeOrderId = null): array
    {
        if ($materialIds === []) {
            return [];
        }
        [$placeholders, $parameters] = SqlFragments::idPlaceholders($materialIds, 'snapshot_material_');
        $statuses = "'" . implode("', '", self::COMMITTING_STATUSES) . "'";
        $query = $this->connection->prepare("SELECT recipe.material_id, SUM(recipe.quantity_required * items.quantity) AS quantity
            FROM order_item_materials recipe JOIN order_items items ON items.id = recipe.order_item_id
            JOIN orders ON orders.id = items.order_id
            WHERE recipe.material_id IN ({$placeholders}) AND orders.deleted_at IS NULL
              AND orders.order_status IN ({$statuses}) AND orders.id <> :exclude
              AND NOT EXISTS (SELECT 1 FROM material_stock_movements movements
                  WHERE movements.order_id = orders.id AND movements.movement_type = 'ORDER_DEDUCTION')
            GROUP BY recipe.material_id");
        $query->execute($parameters + ['exclude' => $excludeOrderId ?? 0]);
        $amounts = [];
        foreach ($query->fetchAll() as $row) {
            $amounts[(int) $row['material_id']] = self::toMillis((string) $row['quantity']);
        }
        return $amounts;
    }

    public static function toMillis(string $value): int
    {
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-')), 2, '');
        $millis = ((int) $whole * 1000) + (int) str_pad(substr($fraction, 0, 3), 3, '0');

        return $negative ? -$millis : $millis;
    }

}
