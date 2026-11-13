<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/** Read-only customer account data; every order lookup is scoped by customer ID. */
final class CustomerAccountReadRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function activeAccount(int $customerId): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT id, first_name, last_name, email, second_email, phone_number, second_phone_number, created_at
             FROM customers_accounts WHERE id = :id AND account_status = 'ACTIVE' LIMIT 1"
        );
        $statement->execute(['id' => $customerId]);
        return $statement->fetch() ?: null;
    }

    public function addresses(int $customerId): array
    {
        $statement = $this->connection->prepare(
            'SELECT address_label, street_address, delegation, governorate, postal_code, is_primary
             FROM customer_addresses WHERE customer_id = :id ORDER BY is_primary DESC, id ASC'
        );
        $statement->execute(['id' => $customerId]);
        return $statement->fetchAll();
    }

    /** A closed set of filters keeps URL values out of SQL expressions. */
    private function orderFilter(string $filter): string
    {
        return match ($filter) {
            'active' => " AND order_status NOT IN ('PICKED_UP', 'DELIVERED', 'CANCELLED')",
            'completed' => " AND order_status IN ('PICKED_UP', 'DELIVERED')",
            'cancelled' => " AND order_status = 'CANCELLED'",
            default => '',
        };
    }

    public function orderCount(int $customerId, string $filter = 'all'): int
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM orders WHERE customer_id = :id AND deleted_at IS NULL' . $this->orderFilter($filter)
        );
        $statement->execute(['id' => $customerId]);
        return (int) $statement->fetchColumn();
    }

    /** Counts always describe this customer's full history, even on a filtered page. */
    public function summary(int $customerId): array
    {
        $statement = $this->connection->prepare(
            "SELECT COUNT(*) AS total,
                COUNT(*) FILTER (WHERE order_status NOT IN ('PICKED_UP', 'DELIVERED', 'CANCELLED')) AS active,
                COUNT(*) FILTER (WHERE order_status IN ('PICKED_UP', 'DELIVERED')) AS completed,
                COUNT(*) FILTER (WHERE order_status = 'CANCELLED') AS cancelled
             FROM orders WHERE customer_id = :id AND deleted_at IS NULL"
        );
        $statement->execute(['id' => $customerId]);
        return array_map('intval', $statement->fetch());
    }

    /** One projection supplies both history cards and the next-order spotlight. */
    private function orderProjection(): string
    {
        return "SELECT id, order_number, order_status, delivery_method, required_date,
            total_amount, required_deposit_amount, created_at,
            COALESCE((SELECT SUM(amount) FROM payments WHERE order_id = orders.id AND payment_status = 'VERIFIED'), 0) AS amount_paid,
            COALESCE((SELECT SUM(amount) FROM payments WHERE order_id = orders.id AND payment_status = 'PENDING_VERIFICATION'), 0) AS pending_amount,
            (SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = orders.id) AS item_quantity,
            (SELECT product_name_snapshot FROM order_items WHERE order_id = orders.id ORDER BY id LIMIT 1) AS first_item_name
            FROM orders WHERE customer_id = :id AND deleted_at IS NULL";
    }

    public function nextOrder(int $customerId): ?array
    {
        $statement = $this->connection->prepare(
            $this->orderProjection() . $this->orderFilter('active') .
            " ORDER BY CASE WHEN order_status = 'WAITING_FOR_PICKUP' THEN 0 ELSE 1 END,
                required_date ASC NULLS LAST, created_at ASC, id ASC LIMIT 1"
        );
        $statement->execute(['id' => $customerId]);
        return $statement->fetch() ?: null;
    }

    public function orders(int $customerId, int $limit, int $offset, string $filter = 'all'): array
    {
        $statement = $this->connection->prepare(
            $this->orderProjection() . $this->orderFilter($filter) .
            ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':id', $customerId, PDO::PARAM_INT);
        $statement->bindValue(':limit', max(1, min(50, $limit)), PDO::PARAM_INT);
        $statement->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function order(int $customerId, int $orderId): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT id, order_number, order_status, delivery_method, required_date,
                    customer_name_snapshot, customer_email_snapshot, customer_phone_snapshot,
                    street_address_snapshot, delegation_snapshot, governorate_snapshot,
                    postal_code_snapshot, subtotal, promo_code_snapshot, promo_discount_amount, manual_discount_type, manual_discount_amount, delivery_fee, total_amount,
                    required_deposit_amount, customer_notes, created_at, updated_at,
                    COALESCE((SELECT SUM(amount) FROM payments WHERE order_id=orders.id AND payment_status='VERIFIED'),0) AS amount_paid,
                    COALESCE((SELECT SUM(amount) FROM payments WHERE order_id=orders.id AND payment_status='PENDING_VERIFICATION'),0) AS pending_amount,
                    EXISTS(SELECT 1 FROM order_status_history WHERE order_id=orders.id AND new_status IN
                      ('PREPARING','PREPARED','WAITING_FOR_PICKUP','PICKED_UP','IN_DELIVERY','DELIVERED')) AS preparation_started
             FROM orders WHERE id = :order_id AND customer_id = :customer_id
               AND deleted_at IS NULL LIMIT 1"
        );
        $statement->execute(['order_id' => $orderId, 'customer_id' => $customerId]);
        return $statement->fetch() ?: null;
    }

    public function orderItems(int $customerId, int $orderId): array
    {
        $statement = $this->connection->prepare(
            'SELECT items.id, items.product_id, items.selected_scent_id, items.catalogue_unit_price_snapshot,
                    items.quantity_unit_price_snapshot, items.quantity_tier_snapshot, items.recipe_source,
                    items.product_name_snapshot, items.color_name_snapshot,
                    items.scent_name_snapshot, items.quantity, items.unit_price_snapshot,
                    items.line_total_snapshot
             FROM order_items items
             INNER JOIN orders ON orders.id = items.order_id
             WHERE orders.id = :order_id AND orders.customer_id = :customer_id
               AND orders.deleted_at IS NULL ORDER BY items.id ASC'
        );
        $statement->execute(['order_id' => $orderId, 'customer_id' => $customerId]);
        return $statement->fetchAll();
    }

    /** Do not expose private administrator notes in the customer timeline. */
    public function history(int $customerId, int $orderId): array
    {
        $q = $this->connection->prepare('SELECT h.id, h.new_status, h.changed_at FROM order_status_history h
            JOIN orders o ON o.id=h.order_id WHERE o.id=:order AND o.customer_id=:customer
            AND o.deleted_at IS NULL ORDER BY h.id');
        $q->execute(['order' => $orderId,'customer' => $customerId]);
        return $q->fetchAll();
    }
}
