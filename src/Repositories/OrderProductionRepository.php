<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Persistence repository for Order Production Repository; it owns the SQL used to read and write this feature's data.
 *
 * DATA FLOW: ManualOrderService and OrderProductionService call this layer with
 * validated values. It reads the orderable catalogue and materials, stores the
 * explicit custom recipes of order items (order_item_materials, migration 017),
 * and reports which order items still lack the data production needs.
 */

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
        foreach ($components->fetchAll() as $row) $sources[(int) $row['component_product_id']] = (int) $row['quantity'];
        $scent = $this->connection->prepare('SELECT fragrance_material_id FROM scents WHERE id = :id');
        $scent->execute(['id' => $scentId]); $chosen = $scent->fetchColumn();
        $fragrances = $this->connection->query('SELECT fragrance_material_id FROM scents WHERE fragrance_material_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
        $lines = [];
        foreach ($sources as $source => $count) {
            $q = $this->connection->prepare("SELECT pm.material_id, pm.quantity_required, m.id, m.name, m.unit, m.unit_cost, m.is_active
                FROM product_materials pm JOIN materials m ON m.id = pm.material_id
                WHERE pm.product_id = :id AND pm.recipe_type = 'ACTUAL' ORDER BY pm.material_id FOR SHARE OF pm, m");
            $q->execute(['id' => $source]); $rows = $q->fetchAll();
            if ($rows === [] && ($source !== $productId || count($sources) === 1)) {
                throw new \DomainException('This product does not have a production recipe. Configure its recipe in Catalogue before adding it to an order.');
            }
            foreach ($rows as $row) {
                $materialId = (int) $row['id'];
                if ($chosen && in_array((string) $materialId, array_map('strval', $fragrances), true)) {
                    $m = $this->connection->prepare('SELECT id, name, unit, unit_cost, is_active FROM materials WHERE id = :id FOR SHARE');
                    $m->execute(['id' => $chosen]); $material = $m->fetch();
                } else { $material = $row; }
                if (!$material || (int) $material['is_active'] !== 1) throw new \DomainException('This recipe contains an inactive material. Configure it in Catalogue before adding it to an order.');
                $id = (int) $material['id'];
                $lines[$id] ??= ['material' => $material, 'millis' => 0];
                $lines[$id]['millis'] += (\App\Services\Money::parseMillis((string) $row['quantity_required']) ?? 0) * $count;
            }
        }
        return array_values(array_map(static fn (array $line): array => ['material' => $line['material'], 'quantity' => \App\Services\Money::formatMillis($line['millis'])], $lines));
    }

    public function pricingRepository(): PricingRepository { return new PricingRepository($this->connection); }
    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * FUNCTION PURPOSE: Fetch the active catalogue products an administrator can put on a manual order.
     *
     * WHAT: returns each product with its English name, calculated price and
     *       cost, minimum quantity, and its active colors and scents.
     * WHY:  manual orders capture the same agreed options as checkout, so the
     *       form and the server validation must offer exactly the options the
     *       shop sells. Names are English because manual orders are recorded in
     *       'en' (OrderRepository::insertManualOrder).
     *
     * @param list<int>|null $productIds Restrict to these IDs; null returns the whole active catalogue.
     * @return array<int, array{id: int, name: string, selling_price: ?string, actual_cost: ?string, minimum_quantity: int, colors: array<int, string>, scents: array<int, string>}>
     */
    public function findOrderableProducts(?array $productIds = null): array
    {
        $parameters = [];
        $restriction = '';
        if ($productIds !== null) {
            if ($productIds === []) {
                return [];
            }
            [$placeholders, $parameters] = $this->idPlaceholders($productIds, 'product_');
            $restriction = "AND products.id IN ({$placeholders})";
        }

        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT products.id, COALESCE(product_text.name, products.slug, CONCAT('Product #', products.id)) AS name,
                    products.selling_price, products.actual_cost, products.minimum_quantity, products.price_rule
             FROM products
             LEFT JOIN product_translations AS product_text
               ON product_text.product_id = products.id AND product_text.language_code = 'en'
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
                'quantity_prices' => $this->pricingRepository()->quantityPrices((int) $row['id']),
                'colors' => [],
                'scents' => [],
            ];
        }
        if ($products === []) {
            return [];
        }

        // Only ACTIVE colors and scents can be chosen — the same rule the public
        // product page and ProductReadinessService apply.
        [$placeholders, $parameters] = $this->idPlaceholders(array_keys($products), 'option_product_');
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

    /**
     * FUNCTION PURPOSE: Fetch the active materials a custom production recipe can use.
     *
     * @return array<int, array{id: int, sku: string, name: string, unit: string, unit_cost: string}>
     */
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

    /**
     * FUNCTION PURPOSE: Store one custom recipe line for an order item.
     *
     * WHAT: copies the material's name, unit and cost into the line.
     * WHY:  the recipe is part of what was agreed; later material edits must
     *       not rewrite it. Migration 017 triggers refuse this insert once the
     *       order has been prepared.
     *
     * @param array{id: int, name: string, unit: string, unit_cost: string} $material
     */
    public function insertItemMaterial(int $orderItemId, array $material, string $quantityRequired): void
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
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

    /**
     * FUNCTION PURPOSE: Remove every custom recipe line of one order item before it is replaced.
     */
    public function deleteItemMaterials(int $orderItemId): void
    {
        $statement = $this->connection->prepare('DELETE FROM order_item_materials WHERE order_item_id = :order_item_id');
        $statement->execute(['order_item_id' => $orderItemId]);
    }

    /**
     * FUNCTION PURPOSE: Fetch the custom recipe lines of every item in one order, grouped by item.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function findItemMaterials(int $orderId): array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
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

    /**
     * FUNCTION PURPOSE: Lock one order item together with the facts that decide whether its recipe may change.
     *
     * WHAT: returns the item, its order status, and whether materials were
     *       already deducted for the order.
     * WHY:  FOR UPDATE on both rows stops a concurrent status change from
     *       preparing the order while its recipe is being replaced.
     *
     * @return array<string, mixed>|null
     */
    public function lockItemForRecipe(int $orderId, int $orderItemId): ?array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
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

    /**
     * FUNCTION PURPOSE: Answer whether materials have already been deducted for one order.
     */
    public function hasOrderDeduction(int $orderId): bool
    {
        $statement = $this->connection->prepare(
            "SELECT 1 FROM material_stock_movements WHERE order_id = :order_id AND movement_type = 'ORDER_DEDUCTION' LIMIT 1"
        );
        $statement->execute(['order_id' => $orderId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * FUNCTION PURPOSE: Record the per-unit production cost of one order item.
     *
     * WHY: order_items.unit_cost_snapshot feeds the profit reports
     *      (AdminReportRepository). Migration 015 freezes the agreed price
     *      columns, not this cost, so it can follow the recipe until the
     *      order is prepared.
     */
    public function updateItemCost(int $orderItemId, ?string $unitCost): void
    {
        $statement = $this->connection->prepare('UPDATE order_items SET unit_cost_snapshot = :cost WHERE id = :id');
        $statement->execute(['cost' => $unitCost, 'id' => $orderItemId]);
    }

    /**
     * FUNCTION PURPOSE: Fetch the current catalogue production cost of one product.
     *
     * WHY: when a custom recipe is removed, the item falls back to the catalogue
     *      recipe, so its cost snapshot falls back to the catalogue cost.
     */
    public function findProductCost(int $productId): ?string
    {
        $statement = $this->connection->prepare('SELECT actual_cost FROM products WHERE id = :id');
        $statement->execute(['id' => $productId]);
        $cost = $statement->fetchColumn();

        return $cost === false || $cost === null ? null : (string) $cost;
    }

    /**
     * FUNCTION PURPOSE: Describe, for each item of the given orders, which production data it has.
     *
     * WHAT: one row per order item with flags:
     *         has_custom_recipe      explicit recipe lines exist (migration 017)
     *         is_set                 the linked product is a set
     *         has_catalogue_recipe   the linked (non-set) product has an ACTUAL recipe
     *         components_missing     number of set components without an ACTUAL recipe
     * WHY:  one query decides "can this item be produced and its materials
     *       deducted?" for the status guard, the order page and the tests, so
     *       they can never disagree. OrderProductionService turns the flags
     *       into readable reasons.
     *
     * @param list<int> $orderIds
     * @return list<array<string, mixed>>
     */
    public function findItemProductionData(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        [$placeholders, $parameters] = $this->idPlaceholders($orderIds, 'order_');

        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
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

    /**
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
