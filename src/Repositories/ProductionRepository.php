<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Persistence repository for Production Repository; it owns the SQL used to read and write this feature's data.
 *
 * DATA FLOW: Services call this layer with validated values. The repository talks to PDO and returns database records or persistence results; it does not render views.
 */

namespace App\Repositories;

use PDO;

final class ProductionRepository
{
    public const QUEUE_STATUSES = ['CONFIRMED', 'PREPARING'];

    private const SORT_COLUMNS = [
        'required_date' => 'orders.required_date',
        'created_at' => 'orders.created_at',
        'total' => 'orders.total_amount',
    ];

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * FUNCTION PURPOSE: Count for queue without loading complete records unnecessarily.
     *
     * @param array<string, mixed> $criteria
     */
    public function countForQueue(array $criteria): int
    {
        [$where, $parameters] = $this->listWhere($criteria);
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare("SELECT COUNT(*) FROM active_orders AS orders {$where}");
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /**
     * FUNCTION PURPOSE: Fetch for queue and return the matching data when it exists.
     *
     * @param array<string, mixed> $criteria
     * @return list<array<string, mixed>>
     */
    public function findForQueue(
        array $criteria,
        string $sort,
        string $direction,
        int $offset,
        int $limit,
        string $today
    ): array {
        [$where, $parameters] = $this->listWhere($criteria);
        $sortDirection = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';

        if ($sort === 'priority') {
            $orderBy = "CASE
                            WHEN orders.required_date < :today_overdue THEN 0
                            WHEN orders.required_date = :today_due THEN 1
                            ELSE 2
                        END {$sortDirection}, orders.required_date {$sortDirection}, orders.id {$sortDirection}";
            $parameters['today_overdue'] = $today;
            $parameters['today_due'] = $today;
        } else {
            $column = self::SORT_COLUMNS[$sort] ?? self::SORT_COLUMNS['required_date'];
            $orderBy = "{$column} {$sortDirection}, orders.id {$sortDirection}";
        }

        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT orders.id, orders.order_number, orders.customer_name_snapshot,
                    orders.order_status, orders.delivery_method, orders.required_date,
                    orders.total_amount, orders.created_at
             FROM active_orders AS orders
             {$where}
             ORDER BY {$orderBy}
             LIMIT :result_limit OFFSET :result_offset"
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue('result_limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $statement->bindValue('result_offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * FUNCTION PURPOSE: Fetch for scope and return the matching data when it exists.
     *
     * @param array<string, mixed> $criteria
     * @return list<array<string, mixed>>
     */
    public function findForScope(array $criteria, int $limit): array
    {
        [$where, $parameters] = $this->listWhere($criteria);
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT orders.id, orders.order_number, orders.customer_name_snapshot,
                    orders.order_status, orders.delivery_method, orders.required_date,
                    orders.total_amount
             FROM active_orders AS orders
             {$where}
             ORDER BY orders.required_date ASC, orders.id ASC
             LIMIT :result_limit"
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue('result_limit', max(1, min($limit, 500)), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * FUNCTION PURPOSE: Count s by date without loading complete records unnecessarily.
     *
     * @param array<string, mixed> $criteria
     * @return list<array{required_date: mixed, order_count: mixed}>
     */
    public function countsByDate(array $criteria, string $monthStart, string $monthEndExclusive): array
    {
        [$where, $parameters] = $this->listWhere($criteria);
        $where .= ' AND orders.required_date >= :month_start AND orders.required_date < :month_end';
        $parameters['month_start'] = $monthStart;
        $parameters['month_end'] = $monthEndExclusive;

        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT orders.required_date, COUNT(*) AS order_count
             FROM active_orders AS orders
             {$where}
             GROUP BY orders.required_date
             ORDER BY orders.required_date ASC"
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /**
     * FUNCTION PURPOSE: Fetch production lines and return the matching data when it exists.
     *
     * @param list<int> $orderIds
     * @return list<array<string, mixed>>
     */
    public function findProductionLines(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        [$directIds, $directParams] = $this->idPlaceholders($orderIds, 'direct_order_');
        [$expandIds, $expandParams] = $this->idPlaceholders($orderIds, 'expand_order_');
        [$assembleIds, $assembleParams] = $this->idPlaceholders($orderIds, 'assemble_order_');
        [$customIds, $customParams] = $this->idPlaceholders($orderIds, 'custom_order_');

        // WHAT: catalogue items are produced (sets expanded into components and
        //       assembled); an item with an explicit custom recipe (migration
        //       017) is produced as ONE line under its own snapshot name, whether
        //       or not it is linked to a catalogue product.
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT production_lines.order_id, orders.order_number, production_lines.product_id,
                    CASE WHEN production_lines.custom_recipe = 1 THEN production_lines.product_name_snapshot
                         ELSE COALESCE(product_text.name, products.slug, CONCAT('Product #', production_lines.product_id))
                    END AS product_name,
                    production_lines.quantity, production_lines.selected_color_id, production_lines.color_name_snapshot,
                    production_lines.selected_scent_id, production_lines.scent_name_snapshot,
                    production_lines.customization_notes, production_lines.line_kind, production_lines.custom_recipe
             FROM (
                 SELECT items.order_id, items.product_id, items.product_name_snapshot, items.quantity,
                        items.selected_color_id, items.color_name_snapshot,
                        items.selected_scent_id, items.scent_name_snapshot,
                        items.customization_notes, 'PRODUCE' AS line_kind, 0 AS custom_recipe
                 FROM order_items AS items
                 WHERE items.order_id IN ({$directIds})
                   AND items.product_id IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM set_components AS sc WHERE sc.set_product_id = items.product_id)
                   AND NOT EXISTS (SELECT 1 FROM order_item_materials AS custom WHERE custom.order_item_id = items.id)

                 UNION ALL

                 SELECT items.order_id, components.component_product_id AS product_id, items.product_name_snapshot,
                        components.quantity * items.quantity AS quantity,
                        items.selected_color_id, items.color_name_snapshot,
                        items.selected_scent_id, items.scent_name_snapshot,
                        items.customization_notes, 'PRODUCE' AS line_kind, 0 AS custom_recipe
                 FROM order_items AS items
                 INNER JOIN set_components AS components ON components.set_product_id = items.product_id
                 WHERE items.order_id IN ({$expandIds})
                   AND items.product_id IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM order_item_materials AS custom WHERE custom.order_item_id = items.id)

                 UNION ALL

                 SELECT items.order_id, items.product_id, items.product_name_snapshot, items.quantity,
                        items.selected_color_id, items.color_name_snapshot,
                        items.selected_scent_id, items.scent_name_snapshot,
                        items.customization_notes, 'ASSEMBLE' AS line_kind, 0 AS custom_recipe
                 FROM order_items AS items
                 WHERE items.order_id IN ({$assembleIds})
                   AND items.product_id IS NOT NULL
                   AND EXISTS (SELECT 1 FROM set_components AS sc WHERE sc.set_product_id = items.product_id)
                   AND NOT EXISTS (SELECT 1 FROM order_item_materials AS custom WHERE custom.order_item_id = items.id)

                 UNION ALL

                 SELECT items.order_id, items.product_id, items.product_name_snapshot, items.quantity,
                        items.selected_color_id, items.color_name_snapshot,
                        items.selected_scent_id, items.scent_name_snapshot,
                        items.customization_notes, 'PRODUCE' AS line_kind, 1 AS custom_recipe
                 FROM order_items AS items
                 WHERE items.order_id IN ({$customIds})
                   AND EXISTS (SELECT 1 FROM order_item_materials AS custom WHERE custom.order_item_id = items.id)
             ) AS production_lines
             INNER JOIN orders ON orders.id = production_lines.order_id
             LEFT JOIN products ON products.id = production_lines.product_id
             LEFT JOIN product_translations AS product_text
               ON product_text.product_id = production_lines.product_id AND product_text.language_code = 'en'
             ORDER BY production_lines.line_kind ASC, product_name ASC, production_lines.order_id ASC"
        );
        $statement->execute($directParams + $expandParams + $assembleParams + $customParams);

        return $statement->fetchAll();
    }

    /**
     * FUNCTION PURPOSE: Fetch material requirements and return the matching data when it exists.
     *
     * @param list<int> $orderIds
     * @return list<array<string, mixed>>
     */
    public function findMaterialRequirements(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        [$directIds, $directParams] = $this->idPlaceholders($orderIds, 'material_direct_order_');
        [$expandIds, $expandParams] = $this->idPlaceholders($orderIds, 'material_expand_order_');
        [$customIds, $customParams] = $this->idPlaceholders($orderIds, 'material_custom_order_');

        // WHAT: the same recipe rule as the PREPARED deduction
        //       (OrderStatusRepository::findMaterialRequirements): a custom
        //       recipe replaces the catalogue recipe for its item.
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT requirements.material_id, materials.sku, materials.name, materials.unit,
                    materials.unit_cost, materials.tracks_stock, materials.stock_quantity,
                    CAST(SUM(requirements.required_quantity) AS DECIMAL(12,3)) AS required_quantity
             FROM (
                 SELECT materials.id AS material_id, recipe.quantity_required * items.quantity AS required_quantity
                 FROM order_items AS items
                 INNER JOIN product_materials AS recipe
                   ON recipe.product_id = items.product_id AND recipe.recipe_type = 'ACTUAL'
                 -- Scent slot: a fragrance line is made with the chosen scent's material.
                 LEFT JOIN scents AS chosen_scent ON chosen_scent.id = items.selected_scent_id
                 INNER JOIN materials ON materials.id = CASE
                     WHEN chosen_scent.fragrance_material_id IS NOT NULL
                      AND recipe.material_id IN (SELECT fragrance_material_id FROM scents WHERE fragrance_material_id IS NOT NULL)
                     THEN chosen_scent.fragrance_material_id
                     ELSE recipe.material_id
                 END
                 INNER JOIN material_types ON material_types.id = materials.material_type_id
                 WHERE items.order_id IN ({$directIds})
                   AND materials.tracks_stock = 1 AND material_types.is_consumable = 1
                   AND NOT EXISTS (SELECT 1 FROM order_item_materials AS custom WHERE custom.order_item_id = items.id)

                 UNION ALL

                 SELECT materials.id AS material_id,
                        component_recipe.quantity_required * components.quantity * items.quantity
                 FROM order_items AS items
                 INNER JOIN set_components AS components ON components.set_product_id = items.product_id
                 INNER JOIN product_materials AS component_recipe
                   ON component_recipe.product_id = components.component_product_id
                  AND component_recipe.recipe_type = 'ACTUAL'
                 -- Scent slot: a fragrance line is made with the chosen scent's material.
                 LEFT JOIN scents AS chosen_scent ON chosen_scent.id = items.selected_scent_id
                 INNER JOIN materials ON materials.id = CASE
                     WHEN chosen_scent.fragrance_material_id IS NOT NULL
                      AND component_recipe.material_id IN (SELECT fragrance_material_id FROM scents WHERE fragrance_material_id IS NOT NULL)
                     THEN chosen_scent.fragrance_material_id
                     ELSE component_recipe.material_id
                 END
                 INNER JOIN material_types ON material_types.id = materials.material_type_id
                 WHERE items.order_id IN ({$expandIds})
                   AND materials.tracks_stock = 1 AND material_types.is_consumable = 1
                   AND NOT EXISTS (SELECT 1 FROM order_item_materials AS custom WHERE custom.order_item_id = items.id)

                 UNION ALL

                 SELECT custom_recipe.material_id, custom_recipe.quantity_required * items.quantity
                 FROM order_items AS items
                 INNER JOIN order_item_materials AS custom_recipe ON custom_recipe.order_item_id = items.id
                 INNER JOIN materials ON materials.id = custom_recipe.material_id
                 INNER JOIN material_types ON material_types.id = materials.material_type_id
                 WHERE items.order_id IN ({$customIds})
                   AND materials.tracks_stock = 1 AND material_types.is_consumable = 1
             ) AS requirements
             INNER JOIN materials ON materials.id = requirements.material_id
             GROUP BY requirements.material_id, materials.sku, materials.name, materials.unit,
                      materials.unit_cost, materials.tracks_stock, materials.stock_quantity
             ORDER BY materials.name ASC"
        );
        $statement->execute($directParams + $expandParams + $customParams);

        return $statement->fetchAll();
    }

    /**
     * FUNCTION PURPOSE: Fetch missing recipe products and return the matching data when it exists.
     *
     * @param list<int> $productIds
     * @return list<array{id: mixed, name: mixed}>
     */
    public function findMissingRecipeProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        [$ids, $parameters] = $this->idPlaceholders($productIds, 'product_');
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT products.id, COALESCE(product_text.name, products.slug, CONCAT('Product #', products.id)) AS name
             FROM products
             LEFT JOIN product_translations AS product_text
               ON product_text.product_id = products.id AND product_text.language_code = 'en'
             WHERE products.id IN ({$ids})
               AND NOT EXISTS (
                   SELECT 1 FROM product_materials AS pm
                   WHERE pm.product_id = products.id AND pm.recipe_type = 'ACTUAL'
               )
             ORDER BY name ASC"
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /**
     * FUNCTION PURPOSE: Count orphaned items without loading complete records unnecessarily.
     *
     * WHAT: an orphaned item has no catalogue product AND no custom recipe, so
     *       production cannot tell what to make or which materials it uses.
     *       Items with a custom recipe are produced from it and are not orphans.
     *
     * @param list<int> $orderIds
     */
    public function countOrphanedItems(array $orderIds): int
    {
        if ($orderIds === []) {
            return 0;
        }

        [$ids, $parameters] = $this->idPlaceholders($orderIds, 'orphan_order_');
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT COUNT(*) FROM order_items AS items
             WHERE items.order_id IN ({$ids}) AND items.product_id IS NULL
               AND NOT EXISTS (SELECT 1 FROM order_item_materials AS custom WHERE custom.order_item_id = items.id)"
        );
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /**
     * FUNCTION PURPOSE: Fetch filter products and return the matching data when it exists.
     *
     * @return list<array{id: mixed, name: mixed}>
     */
    public function findFilterProducts(): array
    {
        $statement = $this->connection->query(
            "SELECT products.id, COALESCE(product_text.name, products.slug, CONCAT('Product #', products.id)) AS name
             FROM products
             LEFT JOIN product_translations AS product_text
               ON product_text.product_id = products.id AND product_text.language_code = 'en'
             WHERE products.is_active = 1
             ORDER BY name ASC"
        );

        return $statement->fetchAll();
    }

    /**
     * FUNCTION PURPOSE: Fetch filter scents and return the matching data when it exists.
     *
     * @return list<array{id: mixed, name: mixed}>
     */
    public function findFilterScents(): array
    {
        $statement = $this->connection->query(
            "SELECT scents.id, COALESCE(scent_text.name, scents.slug) AS name
             FROM scents
             LEFT JOIN scent_translations AS scent_text
               ON scent_text.scent_id = scents.id AND scent_text.language_code = 'en'
             WHERE scents.is_active = 1
             ORDER BY name ASC"
        );

        return $statement->fetchAll();
    }

    /**
     * FUNCTION PURPOSE: Fetch filter colors and return the matching data when it exists.
     *
     * @return list<array{id: mixed, name: mixed}>
     */
    public function findFilterColors(): array
    {
        $statement = $this->connection->query(
            "SELECT colors.id, COALESCE(color_text.name, colors.slug) AS name
             FROM colors
             LEFT JOIN color_translations AS color_text
               ON color_text.color_id = colors.id AND color_text.language_code = 'en'
             WHERE colors.is_active = 1
             ORDER BY name ASC"
        );

        return $statement->fetchAll();
    }

    /**
     * FUNCTION PURPOSE: Prepare a filtered and paginated collection from the supplied query options.
     *
     * @param array<string, mixed> $criteria
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function listWhere(array $criteria): array
    {
        $conditions = ["orders.order_status IN ('" . implode("', '", self::QUEUE_STATUSES) . "')"];
        $parameters = [];

        if (($criteria['search'] ?? null) !== null) {
            $conditions[] = "(orders.order_number LIKE :search_order_number ESCAPE '='
                OR orders.customer_name_snapshot LIKE :search_customer_name ESCAPE '=')";
            $escapedSearch = '%' . $this->escapeLike((string) $criteria['search']) . '%';
            $parameters['search_order_number'] = $escapedSearch;
            $parameters['search_customer_name'] = $escapedSearch;
        }
        if (($criteria['status'] ?? null) !== null) {
            $conditions[] = 'orders.order_status = :order_status';
            $parameters['order_status'] = (string) $criteria['status'];
        }
        if (($criteria['method'] ?? null) !== null) {
            $conditions[] = 'orders.delivery_method = :delivery_method';
            $parameters['delivery_method'] = (string) $criteria['method'];
        }
        if (($criteria['product_id'] ?? null) !== null) {
            $conditions[] = "EXISTS (
                SELECT 1 FROM order_items AS filter_items
                WHERE filter_items.order_id = orders.id
                  AND (
                      filter_items.product_id = :filter_product_id
                      OR EXISTS (
                          SELECT 1 FROM set_components AS filter_components
                          WHERE filter_components.set_product_id = filter_items.product_id
                            AND filter_components.component_product_id = :filter_product_id_component
                      )
                  )
            )";
            $parameters['filter_product_id'] = (int) $criteria['product_id'];
            $parameters['filter_product_id_component'] = (int) $criteria['product_id'];
        }
        if (($criteria['scent_id'] ?? null) !== null) {
            $conditions[] = 'EXISTS (
                SELECT 1 FROM order_items AS filter_scent_items
                WHERE filter_scent_items.order_id = orders.id
                  AND filter_scent_items.selected_scent_id = :filter_scent_id
            )';
            $parameters['filter_scent_id'] = (int) $criteria['scent_id'];
        }
        if (($criteria['color_id'] ?? null) !== null) {
            $conditions[] = 'EXISTS (
                SELECT 1 FROM order_items AS filter_color_items
                WHERE filter_color_items.order_id = orders.id
                  AND filter_color_items.selected_color_id = :filter_color_id
            )';
            $parameters['filter_color_id'] = (int) $criteria['color_id'];
        }
        if (($criteria['date_from'] ?? null) !== null) {
            $conditions[] = 'orders.required_date >= :date_from';
            $parameters['date_from'] = (string) $criteria['date_from'];
        }
        if (($criteria['date_to'] ?? null) !== null) {
            $conditions[] = 'orders.required_date <= :date_to';
            $parameters['date_to'] = (string) $criteria['date_to'];
        }

        return ['WHERE ' . implode(' AND ', $conditions), $parameters];
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

    /**
     * FUNCTION PURPOSE: Escape like so special characters are treated literally.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['=', '%', '_'], ['==', '=%', '=_'], $value);
    }
}
