<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class OrderProductionRepository
{
    /** Resolve catalogue ACTUAL recipes, component quantities and the existing scent slot. */
    public function catalogueRecipe(int $productId, ?int $scentId = null): array
    {
        $components = $this->connection->prepare('SELECT component_product_id, quantity FROM set_components WHERE set_product_id = :id ORDER BY component_product_id FOR SHARE');
        $components->execute(['id' => $productId]);
        $sources = [$productId => 1];
        foreach ($components->fetchAll() as $row) {
            $sources[(int) $row['component_product_id']] = (int) $row['quantity'];
        }
        $scent = $this->connection->prepare('SELECT fragrance_material_id FROM scents WHERE id = :id');
        $scent->execute(['id' => $scentId]);
        $chosen = $scent->fetchColumn();
        $fragrances = $this->connection->query('SELECT fragrance_material_id FROM scents WHERE fragrance_material_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
        $lines = [];
        foreach ($sources as $source => $count) {
            $q = $this->connection->prepare("SELECT pm.material_id, pm.quantity_required, m.id, m.name, m.unit, m.unit_cost, m.is_active
                FROM product_materials pm JOIN materials m ON m.id = pm.material_id
                WHERE pm.product_id = :id AND pm.recipe_type = 'ACTUAL' ORDER BY pm.material_id FOR SHARE OF pm, m");
            $q->execute(['id' => $source]);
            $rows = $q->fetchAll();
            if ($rows === [] && ($source !== $productId || count($sources) === 1)) {
                throw new \DomainException('This product does not have a production recipe. Configure its recipe in Catalogue before adding it to an order.');
            }
            foreach ($rows as $row) {
                $materialId = (int) $row['id'];
                if ($chosen && in_array((string) $materialId, array_map('strval', $fragrances), true)) {
                    $m = $this->connection->prepare('SELECT id, name, unit, unit_cost, is_active FROM materials WHERE id = :id FOR SHARE');
                    $m->execute(['id' => $chosen]);
                    $material = $m->fetch();
                } else {
                    $material = $row;
                }
                if (!$material || (int) $material['is_active'] !== 1) {
                    throw new \DomainException('This recipe contains an inactive material. Configure it in Catalogue before adding it to an order.');
                }
                $id = (int) $material['id'];
                $lines[$id] ??= ['material' => $material, 'millis' => 0];
                $lines[$id]['millis'] += (\App\Services\Money::parseMillis((string) $row['quantity_required']) ?? 0) * $count;
            }
        }
        return array_values(array_map(static fn (array $line): array => ['material' => $line['material'], 'quantity' => \App\Services\Money::formatMillis($line['millis'])], $lines));
    }

    public function pricingRepository(): PricingRepository
    {
        return new PricingRepository($this->connection);
    }

    public function __construct(private readonly PDO $connection)
    {
    }


    public function findOrderableProducts(?array $productIds = null): array
    {
        $parameters = [];
        $restriction = '';
        if ($productIds !== null) {
            if ($productIds === []) {
                return [];
            }
            [$placeholders, $parameters] = SqlFragments::idPlaceholders($productIds, 'product_');
            $restriction = "AND products.id IN ({$placeholders})";
        }
        $statement = $this->connection->prepare(
            "SELECT products.id, COALESCE(product_text.name, products.slug, CONCAT('Product #', products.id)) AS name,
                    products.selling_price, products.actual_cost, products.minimum_quantity, products.price_rule, products.can_purchase_alone, c.slug AS category_slug, products.category_id, products.product_type_id,
                    COALESCE(ct.name, c.slug, 'Uncategorized') AS category_name, COALESCE(tt.name, t.slug, '') AS type_name
             FROM products
             LEFT JOIN product_translations AS product_text
               ON product_text.product_id = products.id AND product_text.language_code = 'en'
             LEFT JOIN categories c ON c.id = products.category_id
             LEFT JOIN category_translations ct ON ct.category_id = c.id AND ct.language_code = 'en'
             LEFT JOIN product_types t ON t.id = products.product_type_id
             LEFT JOIN product_type_translations tt ON tt.product_type_id = t.id AND tt.language_code = 'en'
             WHERE products.is_active = 1 {$restriction}
             ORDER BY name ASC, products.id ASC" . ($productIds === null ? '' : ' FOR SHARE OF products')
        );
        $statement->execute($parameters);

        $products = [];
        foreach ($statement->fetchAll() as $row) {
            $products[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'selling_price' => $row['selling_price'] === null ? null : (string) $row['selling_price'],
                'actual_cost' => $row['actual_cost'] === null ? null : (string) $row['actual_cost'],
                'minimum_quantity' => (int) $row['minimum_quantity'],
                'price_rule' => $row['price_rule'],
                'quantity_prices' => [],
                'can_purchase_alone' => (int)$row['can_purchase_alone'], 'category_slug' => $row['category_slug'], 'category_id' => $row['category_id'], 'product_type_id' => $row['product_type_id'],
                'category_name' => $row['category_name'], 'type_name' => $row['type_name'],
                'colors' => [],
                'scents' => [],
            ];
        }
        if ($products === []) {
            return [];
        }

        // Only ACTIVE colors and scents can be chosen — the same rule the public
        // product page and ProductReadinessService apply.
        [$placeholders, $parameters] = SqlFragments::idPlaceholders(array_keys($products), 'option_product_');
        $tiers = $this->connection->prepare("SELECT * FROM product_quantity_prices WHERE product_id IN ({$placeholders}) ORDER BY minimum_quantity");
        $tiers->execute($parameters);
        foreach ($tiers->fetchAll() as $tier) {
            $products[(int) $tier['product_id']]['quantity_prices'][] = $tier;
        }
        $colors = $this->connection->prepare(
            "SELECT product_colors.product_id, colors.id, COALESCE(color_text.name, colors.slug) AS name
             FROM product_colors
             INNER JOIN colors ON colors.id = product_colors.color_id AND colors.is_active = 1
             LEFT JOIN color_translations AS color_text
               ON color_text.color_id = colors.id AND color_text.language_code = 'en'
             WHERE product_colors.product_id IN ({$placeholders})
             ORDER BY name ASC"
        );
        $colors->execute($parameters);
        foreach ($colors->fetchAll() as $row) {
            $products[(int) $row['product_id']]['colors'][(int) $row['id']] = (string) $row['name'];
        }

        $scents = $this->connection->prepare(
            "SELECT product_scents.product_id, scents.id, COALESCE(scent_text.name, scents.slug) AS name
             FROM product_scents
             INNER JOIN scents ON scents.id = product_scents.scent_id AND scents.is_active = 1
             LEFT JOIN scent_translations AS scent_text
               ON scent_text.scent_id = scents.id AND scent_text.language_code = 'en'
             WHERE product_scents.product_id IN ({$placeholders})
             ORDER BY name ASC"
        );
        $scents->execute($parameters);
        foreach ($scents->fetchAll() as $row) {
            $products[(int) $row['product_id']]['scents'][(int) $row['id']] = (string) $row['name'];
        }

        return $products;
    }


    public function findActiveMaterials(): array
    {
        $statement = $this->connection->query(
            'SELECT id, sku, name, unit, unit_cost FROM materials WHERE is_active = 1 ORDER BY name ASC, id ASC'
        );
        $materials = [];
        foreach ($statement->fetchAll() as $row) {
            $materials[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'sku' => (string) $row['sku'],
                'name' => (string) $row['name'],
                'unit' => (string) $row['unit'],
                'unit_cost' => (string) $row['unit_cost'],
            ];
        }

        return $materials;
    }


    public function insertItemMaterial(int $orderItemId, array $material, string $quantityRequired): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO order_item_materials
                (order_item_id, material_id, material_name_snapshot, unit_snapshot, unit_cost_snapshot, quantity_required)
             VALUES (:order_item_id, :material_id, :name, :unit, :unit_cost, :quantity)'
        );
        $statement->execute([
            'order_item_id' => $orderItemId,
            'material_id' => $material['id'],
            'name' => $material['name'],
            'unit' => $material['unit'],
            'unit_cost' => $material['unit_cost'],
            'quantity' => $quantityRequired,
        ]);
    }


    public function deleteItemMaterials(int $orderItemId): void
    {
        $statement = $this->connection->prepare('DELETE FROM order_item_materials WHERE order_item_id = :order_item_id');
        $statement->execute(['order_item_id' => $orderItemId]);
    }


    public function findItemMaterials(int $orderId): array
    {
        $statement = $this->connection->prepare(
            'SELECT recipe_lines.order_item_id, recipe_lines.material_id, recipe_lines.material_name_snapshot,
                    recipe_lines.unit_snapshot, recipe_lines.unit_cost_snapshot, recipe_lines.quantity_required
             FROM order_item_materials AS recipe_lines
             INNER JOIN order_items AS items ON items.id = recipe_lines.order_item_id
             WHERE items.order_id = :order_id
             ORDER BY recipe_lines.order_item_id ASC, recipe_lines.material_name_snapshot ASC, recipe_lines.id ASC'
        );
        $statement->execute(['order_id' => $orderId]);

        $grouped = [];
        foreach ($statement->fetchAll() as $row) {
            $grouped[(int) $row['order_item_id']][] = $row;
        }

        return $grouped;
    }


    public function lockItemForRecipe(int $orderId, int $orderItemId): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT items.id, items.order_id, items.product_id, items.product_name_snapshot, items.recipe_source,
                    orders.order_status, orders.order_number,
                    EXISTS (
                        SELECT 1 FROM material_stock_movements AS movements
                        WHERE movements.order_id = orders.id AND movements.movement_type = 'ORDER_DEDUCTION'
                    ) AS has_deduction
             FROM order_items AS items
             INNER JOIN orders ON orders.id = items.order_id AND orders.deleted_at IS NULL
             WHERE items.id = :item_id AND items.order_id = :order_id
             FOR UPDATE"
        );
        $statement->execute(['item_id' => $orderItemId, 'order_id' => $orderId]);
        $item = $statement->fetch();

        return $item === false ? null : $item;
    }


    public function hasOrderDeduction(int $orderId): bool
    {
        $statement = $this->connection->prepare(
            "SELECT 1 FROM material_stock_movements WHERE order_id = :order_id AND movement_type = 'ORDER_DEDUCTION' LIMIT 1"
        );
        $statement->execute(['order_id' => $orderId]);

        return $statement->fetchColumn() !== false;
    }


    /** Purchased accessory components contribute their existing cost snapshot, not material recipes. */
    public function customAccessoryCostMillis(int $itemId): ?int
    {
        $q = $this->connection->prepare("SELECT cc.quantity,cc.unit_cost_snapshot FROM custom_request_components cc
            JOIN custom_request_products cp ON cp.id=cc.request_product_id
            JOIN products p ON p.id=cc.product_id JOIN categories c ON c.id=p.category_id
            WHERE cp.converted_order_item_id=:id AND c.slug='accessories'");
        $q->execute(['id' => $itemId]);
        $cost = 0;
        foreach ($q->fetchAll() as $row) {
            if ($row['unit_cost_snapshot'] === null) {
                return null;
            }
            $cost += (\App\Services\Money::parseMillis((string)$row['unit_cost_snapshot']) ?? 0) * (int)$row['quantity'];
        }
        return $cost;
    }

    public function updateItemCost(int $orderItemId, ?string $unitCost): void
    {
        $statement = $this->connection->prepare('UPDATE order_items SET unit_cost_snapshot = :cost WHERE id = :id');
        $statement->execute(['cost' => $unitCost, 'id' => $orderItemId]);
    }


    public function findProductCost(int $productId): ?string
    {
        $statement = $this->connection->prepare('SELECT actual_cost FROM products WHERE id = :id');
        $statement->execute(['id' => $productId]);
        $cost = $statement->fetchColumn();

        return $cost === false || $cost === null ? null : (string) $cost;
    }


    public function findItemProductionData(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        [$placeholders, $parameters] = SqlFragments::idPlaceholders($orderIds, 'order_');
        $statement = $this->connection->prepare(
            "SELECT items.id, items.order_id, items.product_id, items.product_name_snapshot, items.recipe_source,
                    EXISTS (SELECT 1 FROM order_item_materials AS custom WHERE custom.order_item_id = items.id) AS has_custom_recipe,
                    EXISTS (SELECT 1 FROM set_components AS sc WHERE sc.set_product_id = items.product_id) AS is_set,
                    EXISTS (
                        SELECT 1 FROM product_materials AS recipe
                        WHERE recipe.product_id = items.product_id AND recipe.recipe_type = 'ACTUAL'
                    ) AS has_catalogue_recipe,
                    (SELECT COUNT(*) FROM set_components AS sc
                     WHERE sc.set_product_id = items.product_id
                       AND NOT EXISTS (
                           SELECT 1 FROM product_materials AS component_recipe
                           WHERE component_recipe.product_id = sc.component_product_id
                             AND component_recipe.recipe_type = 'ACTUAL'
                       )) AS components_missing
             FROM order_items AS items
             WHERE items.order_id IN ({$placeholders})
             ORDER BY items.order_id ASC, items.id ASC"
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

}
