<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Persistence repository for Order Status Repository; it owns the SQL used to read and write this feature's data.
 *
 * DATA FLOW: Services call this layer with validated values. The repository talks to PDO and returns database records or persistence results; it does not render views.
 */

namespace App\Repositories;

use PDO;

final class OrderStatusRepository
{
    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lockOrder(int $orderId): ?array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'SELECT id, order_number, order_status, delivery_method,
                    total_amount, required_deposit_amount, price_locked_at
             FROM active_orders AS orders
             WHERE id = :order_id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['order_id' => $orderId]);
        $order = $statement->fetch();

        return $order === false ? null : $order;
    }

    public function verifiedPaymentTotalForUpdate(int $orderId): string
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'SELECT payment_status, amount
             FROM payments
             WHERE order_id = :order_id
             ORDER BY id ASC
             FOR UPDATE'
        );
        $statement->execute(['order_id' => $orderId]);
        $totalMillis = 0;
        foreach ($statement->fetchAll() as $payment) {
            if ((string) $payment['payment_status'] === 'VERIFIED') {
                $totalMillis += $this->moneyToMillis((string) $payment['amount']);
            }
        }

        return $this->millisToMoney($totalMillis);
    }

    /**
     * FUNCTION PURPOSE: Answer whether stock deduction exists or is present.
     */
    public function hasStockDeduction(int $orderId): bool
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT 1
             FROM material_stock_movements
             WHERE order_id = :order_id AND movement_type = 'ORDER_DEDUCTION'
             LIMIT 1"
        );
        $statement->execute(['order_id' => $orderId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * FUNCTION PURPOSE: Answer whether the order has ever entered the given status.
     *
     * WHY: the status history is the reliable record that an order was already
     *      prepared once (and its materials handled), even after a correction
     *      moved it back to an earlier status.
     */
    public function hasReachedStatus(int $orderId, string $status): bool
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'SELECT 1 FROM order_status_history WHERE order_id = :order_id AND new_status = :status LIMIT 1'
        );
        $statement->execute(['order_id' => $orderId, 'status' => $status]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * FUNCTION PURPOSE: Fetch material requirements and return the matching data when it exists.
     *
     * WHAT: sums, per tracked consumable material, what the order's items need:
     *         1. an item with an explicit custom recipe (order_item_materials,
     *            migration 017) uses ONLY that recipe;
     *         2. otherwise a product uses its catalogue ACTUAL recipe, and a set
     *            adds its components' recipes × component quantity;
     *         3. in those catalogue recipes, a fragrance line (a material linked
     *            to a scent) is made with the order's CHOSEN scent's material,
     *            so a rose order consumes rose oil, not the recipe's example oil.
     * WHY:  the custom recipe is the agreed record of how that item is made, so
     *       it replaces — never adds to — the catalogue recipe.
     *
     * @return list<array{material_id: mixed, required_quantity: mixed}>
     */
    public function findMaterialRequirements(int $orderId): array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT requirements.material_id,
                    CAST(SUM(requirements.required_quantity) AS DECIMAL(12,3)) AS required_quantity
             FROM (
                 SELECT materials.id AS material_id,
                        recipe.quantity_required * items.quantity AS required_quantity
                 FROM order_items AS items
                 INNER JOIN product_materials AS recipe
                   ON recipe.product_id = items.product_id
                  AND recipe.recipe_type = 'ACTUAL'
                 -- Scent slot: a fragrance line is made with the chosen scent's material.
                 LEFT JOIN scents AS chosen_scent ON chosen_scent.id = items.selected_scent_id
                 INNER JOIN materials ON materials.id = CASE
                     WHEN chosen_scent.fragrance_material_id IS NOT NULL
                      AND recipe.material_id IN (SELECT fragrance_material_id FROM scents WHERE fragrance_material_id IS NOT NULL)
                     THEN chosen_scent.fragrance_material_id
                     ELSE recipe.material_id
                 END
                 INNER JOIN material_types ON material_types.id = materials.material_type_id
                 WHERE items.order_id = :direct_order_id
                   AND materials.tracks_stock = 1
                   AND material_types.is_consumable = 1
                   AND NOT EXISTS (SELECT 1 FROM order_item_materials AS custom WHERE custom.order_item_id = items.id)

                 UNION ALL

                 SELECT materials.id AS material_id,
                        component_recipe.quantity_required * components.quantity * items.quantity
                 FROM order_items AS items
                 INNER JOIN set_components AS components
                   ON components.set_product_id = items.product_id
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
                 WHERE items.order_id = :component_order_id
                   AND materials.tracks_stock = 1
                   AND material_types.is_consumable = 1
                   AND NOT EXISTS (SELECT 1 FROM order_item_materials AS custom WHERE custom.order_item_id = items.id)

                 UNION ALL

                 SELECT custom_recipe.material_id,
                        custom_recipe.quantity_required * items.quantity
                 FROM order_items AS items
                 INNER JOIN order_item_materials AS custom_recipe ON custom_recipe.order_item_id = items.id
                 INNER JOIN materials ON materials.id = custom_recipe.material_id
                 INNER JOIN material_types ON material_types.id = materials.material_type_id
                 WHERE items.order_id = :custom_order_id
                   AND materials.tracks_stock = 1
                   AND material_types.is_consumable = 1
             ) AS requirements
             GROUP BY requirements.material_id
             ORDER BY requirements.material_id ASC"
        );
        $statement->execute([
            'direct_order_id' => $orderId,
            'component_order_id' => $orderId,
            'custom_order_id' => $orderId,
        ]);

        return $statement->fetchAll();
    }

    /**
     * FUNCTION PURPOSE: Update the stored status values.
     */
    public function updateStatus(int $orderId, string $currentStatus, string $newStatus): void
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'UPDATE orders
             SET order_status = :new_status,
                 price_locked_at = CASE
                     WHEN :lock_price = 1 AND price_locked_at IS NULL THEN CURRENT_TIMESTAMP
                     ELSE price_locked_at
                 END
             WHERE id = :order_id AND order_status = :current_status'
        );
        $statement->execute([
            'new_status' => $newStatus,
            'lock_price' => $newStatus === 'PREPARED' ? 1 : 0,
            'order_id' => $orderId,
            'current_status' => $currentStatus,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \DomainException('The order changed before this transition could be saved. Please review it again.');
        }
    }

    /**
     * FUNCTION PURPOSE: Add history after enforcing the relevant rules.
     */
    public function addHistory(
        int $orderId,
        string $previousStatus,
        string $newStatus,
        ?int $administratorId,
        ?string $note
    ): void {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "INSERT INTO order_status_history
                (order_id, previous_status, new_status,
                 changed_by_administrator_id, change_source, change_note)
             VALUES
                (:order_id, :previous_status, :new_status,
                 :administrator_id, 'ADMINISTRATOR', :change_note)"
        );
        $statement->execute([
            'order_id' => $orderId,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'administrator_id' => $administratorId,
            'change_note' => $note,
        ]);
    }

    /**
     * FUNCTION PURPOSE: Convert to millis using integer thousandths to avoid floating-point errors.
     */
    private function moneyToMillis(string $amount): int
    {
        // VALIDATION IDIOM: Test the complete string against a regular-expression format before accepting it.
        if (preg_match('/^(\d+)(?:\.(\d{1,3}))?$/', $amount, $matches) !== 1) {
            return 0;
        }
        return ((int) $matches[1] * 1000) + (int) str_pad($matches[2] ?? '', 3, '0');
    }

    /**
     * FUNCTION PURPOSE: Convert to money between integer thousandths and a money string.
     */
    private function millisToMoney(int $millis): string
    {
        return intdiv($millis, 1000) . '.' . str_pad((string) ($millis % 1000), 3, '0', STR_PAD_LEFT);
    }
}
