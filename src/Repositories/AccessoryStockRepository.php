<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Persistence repository for Accessory Stock Repository; it owns the SQL used to read and write this feature's data.
 *
 * DATA FLOW: AccessoryStockService calls this layer inside a transaction. It
 * expands order lines into the stock-tracked products they consume (directly
 * or inside sets), locks those product rows, reports what open orders have
 * committed, and changes products.stock_quantity together with a
 * product_stock_movements ledger row (migration 018).
 *
 * A product is "stock-tracked" when products.stock_quantity IS NOT NULL;
 * AdminProductService only allows that for accessories.
 */

namespace App\Repositories;

use DomainException;
use PDO;

final class AccessoryStockRepository
{
    // Orders that still need their accessories but have not had them taken
    // from the shelf yet (that happens at PREPARED).
    private const COMMITTING_STATUSES = ['REQUESTED', 'CONFIRMED', 'PREPARING'];

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * FUNCTION PURPOSE: Expand ordered products into the stock-tracked products they consume.
     *
     * WHAT: an ordered accessory consumes itself; an ordered set consumes each
     *       accessory component × component quantity.
     * WHY:  used before an order exists (checkout, manual orders), when there
     *       are no order_items rows to query yet.
     *
     * @param array<int, int> $lines Ordered product ID => quantity.
     * @return array<int, int> Stock-tracked product ID => units needed.
     */
    public function expandLines(array $lines): array
    {
        $lines = array_filter($lines, static fn (int $quantity): bool => $quantity > 0);
        if ($lines === []) {
            return [];
        }
        [$placeholders, $parameters] = $this->idPlaceholders(array_keys($lines), 'direct_');
        [$setPlaceholders, $setParameters] = $this->idPlaceholders(array_keys($lines), 'set_');

        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT products.id AS ordered_id, products.id AS product_id, 1 AS per_unit
             FROM products
             WHERE products.id IN ({$placeholders}) AND products.stock_quantity IS NOT NULL

             UNION ALL

             SELECT components.set_product_id, components.component_product_id, components.quantity
             FROM set_components AS components
             INNER JOIN products ON products.id = components.component_product_id AND products.stock_quantity IS NOT NULL
             WHERE components.set_product_id IN ({$setPlaceholders})"
        );
        $statement->execute($parameters + $setParameters);

        $needed = [];
        foreach ($statement->fetchAll() as $row) {
            $productId = (int) $row['product_id'];
            $needed[$productId] = ($needed[$productId] ?? 0) + (int) $row['per_unit'] * $lines[(int) $row['ordered_id']];
        }

        return $needed;
    }

    /**
     * FUNCTION PURPOSE: Calculate what one saved order needs from accessory stock.
     *
     * @return array<int, int> Stock-tracked product ID => units needed.
     */
    public function orderRequirements(int $orderId): array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT requirements.product_id, SUM(requirements.quantity) AS quantity
             FROM (
                 SELECT items.product_id, items.quantity
                 FROM order_items AS items
                 INNER JOIN products ON products.id = items.product_id AND products.stock_quantity IS NOT NULL
                 WHERE items.order_id = :direct_order_id

                 UNION ALL

                 SELECT components.component_product_id, components.quantity * items.quantity
                 FROM order_items AS items
                 INNER JOIN set_components AS components ON components.set_product_id = items.product_id
                 INNER JOIN products ON products.id = components.component_product_id AND products.stock_quantity IS NOT NULL
                 WHERE items.order_id = :set_order_id
             ) AS requirements
             GROUP BY requirements.product_id"
        );
        $statement->execute(['direct_order_id' => $orderId, 'set_order_id' => $orderId]);

        $needed = [];
        foreach ($statement->fetchAll() as $row) {
            $needed[(int) $row['product_id']] = (int) $row['quantity'];
        }

        return $needed;
    }

    /**
     * FUNCTION PURPOSE: Lock stock-tracked product rows and return their stock and names.
     *
     * WHY: FOR UPDATE serialises every availability check and movement on the
     *      same accessory, so two orders can never both take the last unit.
     *      Rows are locked in ID order so concurrent orders cannot deadlock.
     *
     * @param list<int> $productIds
     * @return array<int, array{stock: int, name: string}>
     */
    public function lockProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        sort($productIds);
        [$placeholders, $parameters] = $this->idPlaceholders($productIds, 'product_');
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT products.id, products.stock_quantity,
                    COALESCE(product_text.name, products.slug, CONCAT('Product #', products.id)) AS name
             FROM products
             LEFT JOIN product_translations AS product_text
               ON product_text.product_id = products.id AND product_text.language_code = 'en'
             WHERE products.id IN ({$placeholders}) AND products.stock_quantity IS NOT NULL
             ORDER BY products.id ASC
             FOR UPDATE"
        );
        $statement->execute($parameters);

        $products = [];
        foreach ($statement->fetchAll() as $row) {
            $products[(int) $row['id']] = ['stock' => (int) $row['stock_quantity'], 'name' => (string) $row['name']];
        }

        return $products;
    }

    /**
     * FUNCTION PURPOSE: Sum what open, not-yet-prepared orders have committed of each product.
     *
     * WHAT: counts the accessory needs of orders that are not in Trash, are
     *       REQUESTED/CONFIRMED/PREPARING, and do not already hold that
     *       product (an order corrected back from PREPARED keeps its stock).
     * WHY:  shelf stock minus these commitments is what a new order may use,
     *       so accepted orders are never oversold before they are prepared.
     *
     * @param list<int> $productIds
     * @return array<int, int> Product ID => committed units.
     */
    public function committedQuantities(array $productIds, ?int $excludeOrderId = null): array
    {
        if ($productIds === []) {
            return [];
        }
        [$directPlaceholders, $directParameters] = $this->idPlaceholders($productIds, 'direct_product_');
        [$setPlaceholders, $setParameters] = $this->idPlaceholders($productIds, 'set_product_');
        $statuses = "'" . implode("', '", self::COMMITTING_STATUSES) . "'";

        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            "SELECT requirements.product_id, SUM(requirements.quantity) AS quantity
             FROM (
                 SELECT items.order_id, items.product_id, items.quantity
                 FROM order_items AS items
                 WHERE items.product_id IN ({$directPlaceholders})

                 UNION ALL

                 SELECT items.order_id, components.component_product_id, components.quantity * items.quantity
                 FROM order_items AS items
                 INNER JOIN set_components AS components ON components.set_product_id = items.product_id
                 WHERE components.component_product_id IN ({$setPlaceholders})
             ) AS requirements
             INNER JOIN orders ON orders.id = requirements.order_id
             WHERE orders.deleted_at IS NULL
               AND orders.order_status IN ({$statuses})
               AND orders.id <> :exclude_order_id
               AND NOT EXISTS (
                   SELECT 1 FROM product_stock_movements AS held
                   WHERE held.order_id = orders.id AND held.product_id = requirements.product_id
                   GROUP BY held.order_id, held.product_id
                   HAVING SUM(held.quantity_change) < 0
               )
             GROUP BY requirements.product_id"
        );
        $statement->execute($directParameters + $setParameters + ['exclude_order_id' => $excludeOrderId ?? 0]);

        $committed = [];
        foreach ($statement->fetchAll() as $row) {
            $committed[(int) $row['product_id']] = (int) $row['quantity'];
        }

        return $committed;
    }

    /**
     * FUNCTION PURPOSE: Report how many units of each product an order currently holds.
     *
     * WHAT: −(sum of its ORDER_DEDUCTION and ORDER_RESTORATION changes).
     * WHY:  deduction takes only what is not yet held and restoration returns
     *       only what is held, so repeating either is harmless.
     *
     * @return array<int, int> Product ID => units held (> 0 only).
     */
    public function heldByOrder(int $orderId): array
    {
        $statement = $this->connection->prepare(
            'SELECT product_id, -SUM(quantity_change) AS held
             FROM product_stock_movements
             WHERE order_id = :order_id
             GROUP BY product_id
             HAVING SUM(quantity_change) < 0'
        );
        $statement->execute(['order_id' => $orderId]);

        $held = [];
        foreach ($statement->fetchAll() as $row) {
            $held[(int) $row['product_id']] = (int) $row['held'];
        }

        return $held;
    }

    /**
     * FUNCTION PURPOSE: Answer whether an order ever returned accessories to stock.
     */
    public function hasRestoration(int $orderId): bool
    {
        $statement = $this->connection->prepare(
            "SELECT 1 FROM product_stock_movements WHERE order_id = :order_id AND movement_type = 'ORDER_RESTORATION' LIMIT 1"
        );
        $statement->execute(['order_id' => $orderId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * FUNCTION PURPOSE: Take units out of, or put units back into, one product's stock with a ledger row.
     *
     * WHAT: a guarded UPDATE changes the stock only when enough units exist,
     *       then the movement is recorded with its before/after balance.
     * WHY:  the condition lives in the UPDATE itself, so the database — not a
     *       stale PHP value — decides whether stock is sufficient. The caller
     *       has already locked the row (lockProducts) inside its transaction.
     *
     * @throws DomainException when a deduction would make the stock negative.
     */
    public function applyMovement(int $productId, string $type, int $change, ?int $orderId, ?int $administratorId, ?string $note): void
    {
        $before = $this->connection->prepare('SELECT stock_quantity FROM products WHERE id = :id AND stock_quantity IS NOT NULL');
        $before->execute(['id' => $productId]);
        $stockBefore = $before->fetchColumn();
        if ($stockBefore === false || $stockBefore === null) {
            throw new DomainException('This product does not track stock.');
        }

        // stock_quantity is UNSIGNED, so subtraction is guarded with ">=" rather
        // than computing a negative intermediate value.
        if ($change < 0) {
            $update = $this->connection->prepare(
                'UPDATE products SET stock_quantity = stock_quantity - :units WHERE id = :id AND stock_quantity >= :required'
            );
            $update->execute(['units' => -$change, 'id' => $productId, 'required' => -$change]);
        } else {
            $update = $this->connection->prepare('UPDATE products SET stock_quantity = stock_quantity + :units WHERE id = :id');
            $update->execute(['units' => $change, 'id' => $productId]);
        }
        if ($change !== 0 && $update->rowCount() !== 1) {
            throw new DomainException('Not enough stock for this product.');
        }

        $this->insertMovement($productId, $type, $change, (int) $stockBefore, (int) $stockBefore + $change, $orderId, $administratorId, $note);
    }

    /**
     * FUNCTION PURPOSE: Record a stock value that the product form has already written.
     *
     * WHY: the admin form saves stock_quantity with the rest of the product
     *      (AdminProductRepository::updateCore); this adds the matching
     *      OPENING/ADJUSTMENT ledger row in the same transaction.
     */
    public function recordFormChange(int $productId, string $type, int $stockBefore, int $stockAfter, ?string $note): void
    {
        $this->insertMovement($productId, $type, $stockAfter - $stockBefore, $stockBefore, $stockAfter, null, null, $note);
    }

    /**
     * FUNCTION PURPOSE: Fetch the latest stock movements of one product for its admin page.
     *
     * @return list<array<string, mixed>>
     */
    public function recentMovements(int $productId, int $limit = 10): array
    {
        $statement = $this->connection->prepare(
            'SELECT movements.movement_type, movements.quantity_change, movements.stock_before, movements.stock_after,
                    movements.note, movements.created_at, orders.order_number
             FROM product_stock_movements AS movements
             LEFT JOIN orders ON orders.id = movements.order_id
             WHERE movements.product_id = :product_id
             ORDER BY movements.id DESC
             LIMIT :result_limit'
        );
        $statement->bindValue('product_id', $productId, PDO::PARAM_INT);
        $statement->bindValue('result_limit', max(1, min($limit, 50)), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * FUNCTION PURPOSE: Insert one immutable ledger row.
     */
    private function insertMovement(int $productId, string $type, int $change, int $before, int $after, ?int $orderId, ?int $administratorId, ?string $note): void
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'INSERT INTO product_stock_movements
                (product_id, order_id, movement_type, quantity_change, stock_before, stock_after, created_by_administrator_id, note)
             VALUES (:product_id, :order_id, :type, :change, :before, :after, :administrator_id, :note)'
        );
        $statement->execute([
            'product_id' => $productId,
            'order_id' => $orderId,
            'type' => $type,
            'change' => $change,
            'before' => $before,
            'after' => $after,
            'administrator_id' => $administratorId,
            'note' => $note,
        ]);
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
