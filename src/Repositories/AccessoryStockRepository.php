<?php

declare(strict_types=1);

namespace App\Repositories;

use DomainException;
use PDO;

final class AccessoryStockRepository
{
    // Orders that still need their accessories but have not had them taken
    // from the shelf yet (that happens at PREPARED).
    private const COMMITTING_STATUSES = ['REQUESTED', 'CONFIRMED', 'PREPARING'];


    public function __construct(private readonly PDO $connection) {}


    public function expandLines(array $lines): array
    {
        $lines = array_filter($lines, static fn(int $quantity): bool => $quantity > 0);
        if ($lines === []) {
            return [];
        }
        [$placeholders, $parameters] = SqlFragments::idPlaceholders(array_keys($lines), 'direct_');
        [$setPlaceholders, $setParameters] = SqlFragments::idPlaceholders(array_keys($lines), 'set_');
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


    public function orderRequirements(int $orderId): array
    {
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
                 UNION ALL
                 SELECT cc.product_id, cc.quantity * items.quantity
                 FROM custom_request_components cc JOIN custom_request_products cp ON cp.id=cc.request_product_id
                 JOIN order_items items ON items.id=cp.converted_order_item_id
                 JOIN products p ON p.id=cc.product_id AND p.stock_quantity IS NOT NULL
                 WHERE items.order_id=:custom_order_id
                 UNION ALL
                 SELECT sc.component_product_id, sc.quantity * cc.quantity * items.quantity
                 FROM custom_request_components cc JOIN custom_request_products cp ON cp.id=cc.request_product_id
                 JOIN order_items items ON items.id=cp.converted_order_item_id
                 JOIN set_components sc ON sc.set_product_id=cc.product_id
                 JOIN products p ON p.id=sc.component_product_id AND p.stock_quantity IS NOT NULL
                 WHERE items.order_id=:custom_set_order_id
             ) AS requirements
             GROUP BY requirements.product_id"
        );
        $statement->execute(['direct_order_id' => $orderId, 'set_order_id' => $orderId, 'custom_order_id' => $orderId, 'custom_set_order_id' => $orderId]);

        $needed = [];
        foreach ($statement->fetchAll() as $row) {
            $needed[(int) $row['product_id']] = (int) $row['quantity'];
        }

        return $needed;
    }


    public function lockProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        sort($productIds);
        [$placeholders, $parameters] = SqlFragments::idPlaceholders($productIds, 'product_');
        $statement = $this->connection->prepare(
            "SELECT products.id, products.stock_quantity,
                    COALESCE(product_text.name, products.slug, CONCAT('Product #', products.id)) AS name
             FROM products
             LEFT JOIN product_translations AS product_text
               ON product_text.product_id = products.id AND product_text.language_code = 'en'
             WHERE products.id IN ({$placeholders}) AND products.stock_quantity IS NOT NULL
             ORDER BY products.id ASC
             FOR UPDATE OF products"
        );
        $statement->execute($parameters);

        $products = [];
        foreach ($statement->fetchAll() as $row) {
            $products[(int) $row['id']] = ['stock' => (int) $row['stock_quantity'], 'name' => (string) $row['name']];
        }

        return $products;
    }


    public function committedQuantities(array $productIds, ?int $excludeOrderId = null): array
    {
        if ($productIds === []) {
            return [];
        }
        [$directPlaceholders, $directParameters] = SqlFragments::idPlaceholders($productIds, 'direct_product_');
        [$setPlaceholders, $setParameters] = SqlFragments::idPlaceholders($productIds, 'set_product_');
        $statuses = "'" . implode("', '", self::COMMITTING_STATUSES) . "'";
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
                 UNION ALL
                 SELECT items.order_id, cc.product_id, cc.quantity * items.quantity
                 FROM custom_request_components cc JOIN custom_request_products cp ON cp.id=cc.request_product_id
                 JOIN order_items items ON items.id=cp.converted_order_item_id
                 WHERE cc.product_id IN ({$directPlaceholders})
                 UNION ALL
                 SELECT items.order_id, sc.component_product_id, sc.quantity * cc.quantity * items.quantity
                 FROM custom_request_components cc JOIN custom_request_products cp ON cp.id=cc.request_product_id
                 JOIN order_items items ON items.id=cp.converted_order_item_id
                 JOIN set_components sc ON sc.set_product_id=cc.product_id
                 WHERE sc.component_product_id IN ({$setPlaceholders})
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


    public function hasRestoration(int $orderId): bool
    {
        $statement = $this->connection->prepare(
            "SELECT 1 FROM product_stock_movements WHERE order_id = :order_id AND movement_type = 'ORDER_RESTORATION' LIMIT 1"
        );
        $statement->execute(['order_id' => $orderId]);

        return $statement->fetchColumn() !== false;
    }


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


    public function recordFormChange(int $productId, string $type, int $stockBefore, int $stockAfter, ?string $note): void
    {
        $this->insertMovement($productId, $type, $stockAfter - $stockBefore, $stockBefore, $stockAfter, null, null, $note);
    }


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


    private function insertMovement(int $productId, string $type, int $change, int $before, int $after, ?int $orderId, ?int $administratorId, ?string $note): void
    {
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

}
