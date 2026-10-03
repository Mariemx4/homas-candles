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
            "SELECT id, first_name, last_name, email, phone_number, second_phone_number, created_at
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

    public function orderCount(int $customerId): int
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM orders WHERE customer_id = :id AND deleted_at IS NULL'
        );
        $statement->execute(['id' => $customerId]);
        return (int) $statement->fetchColumn();
    }

    public function orders(int $customerId, int $limit, int $offset): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, order_number, order_status, required_date, total_amount, created_at
             FROM orders WHERE customer_id = :id AND deleted_at IS NULL
             ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':id', $customerId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function order(int $customerId, int $orderId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, order_number, order_status, delivery_method, required_date,
                    customer_name_snapshot, customer_email_snapshot, customer_phone_snapshot,
                    street_address_snapshot, delegation_snapshot, governorate_snapshot,
                    postal_code_snapshot, subtotal, promo_code_snapshot, promo_discount_amount, manual_discount_type, manual_discount_amount, delivery_fee, total_amount,
                    required_deposit_amount, customer_notes, created_at
             FROM orders WHERE id = :order_id AND customer_id = :customer_id
               AND deleted_at IS NULL LIMIT 1'
        );
        $statement->execute(['order_id' => $orderId, 'customer_id' => $customerId]);
        return $statement->fetch() ?: null;
    }

    public function orderItems(int $customerId, int $orderId): array
    {
        $statement = $this->connection->prepare(
            'SELECT items.product_name_snapshot, items.color_name_snapshot,
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
}
