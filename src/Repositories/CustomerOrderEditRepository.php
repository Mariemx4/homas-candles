<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Database writes for a customer editing or cancelling their own order.
 *
 * Every method runs inside the transaction opened by CustomerOrderService,
 * which has already locked the order. Money values arrive already formatted
 * as decimal strings (see Money::formatMillis()).
 */
final class CustomerOrderEditRepository
{
    /** Statuses that mean the workshop has started on the order. */
    private const PREPARATION_STATUSES = "'PREPARING','PREPARED','IN_DELIVERY','WAITING_FOR_PICKUP','DELIVERED','PICKED_UP'";

    public function __construct(private readonly PDO $connection)
    {
    }

    /** True once the order has ever reached a preparation (or later) status. */
    public function preparationHasStarted(int $orderId): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1
             FROM order_status_history
             WHERE order_id = :id
               AND new_status IN (' . self::PREPARATION_STATUSES . ')
             LIMIT 1'
        );
        $statement->execute(['id' => $orderId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * Locks the product rows so their prices cannot change while the order is repriced.
     *
     * @param list<int|string> $productIds
     */
    public function lockProducts(array $productIds): void
    {
        if ($productIds === []) {
            return;
        }
        $statement = $this->connection->prepare(
            'SELECT id FROM products WHERE id IN (' . implode(',', array_fill(0, count($productIds), '?')) . ') ORDER BY id FOR UPDATE'
        );
        $statement->execute($productIds);
    }

    /**
     * Stores the before/after snapshot of an edit and returns its ID.
     * The original sale and every revision are kept for the audit trail.
     */
    public function recordEdit(int $orderId, int $customerId, string $previousTotal, string $newTotal, string $detailsJson): string
    {
        $statement = $this->connection->prepare(
            'INSERT INTO customer_order_edits (order_id, customer_id, previous_total, new_total, edit_details)
             VALUES (:order, :customer, :previous, :new, CAST(:details AS jsonb))
             RETURNING id'
        );
        $statement->execute([
            'order' => $orderId,
            'customer' => $customerId,
            'previous' => $previousTotal,
            'new' => $newTotal,
            'details' => $detailsJson,
        ]);

        return (string) $statement->fetchColumn();
    }

    /**
     * Tells the database trigger from migration 028 which edit is allowed to
     * change price snapshots in this transaction. Pass '' to clear it.
     */
    public function setActiveEdit(string $editId): void
    {
        $statement = $this->connection->prepare("SELECT set_config('homas.customer_edit_id', :id, true)");
        $statement->execute(['id' => $editId]);
    }

    /**
     * @param array{quantity: int, unit: string, total: string, base: ?string, quantity_unit: ?string, tier: mixed} $pricing
     */
    public function updateItemPricing(int $orderId, int $itemId, array $pricing): void
    {
        $statement = $this->connection->prepare(
            'UPDATE order_items
             SET quantity = :quantity,
                 unit_price_snapshot = :unit,
                 line_total_snapshot = :total,
                 catalogue_unit_price_snapshot = :base,
                 quantity_unit_price_snapshot = :quantity_unit,
                 quantity_tier_snapshot = :tier
             WHERE id = :id AND order_id = :order'
        );
        $statement->execute($pricing + ['id' => $itemId, 'order' => $orderId]);
    }

    /**
     * Saves the delivery details and the recalculated totals.
     *
     * @param array<string, mixed> $values delivery_method, required_date, address snapshots,
     *                                     customer_notes, subtotal, fee, total, deposit, promo, manual, savings
     */
    public function updateOrder(int $orderId, array $values): void
    {
        $statement = $this->connection->prepare(
            'UPDATE orders
             SET delivery_method = :delivery_method,
                 required_date = :required_date,
                 street_address_snapshot = :street_address,
                 delegation_snapshot = :delegation,
                 governorate_snapshot = :governorate,
                 postal_code_snapshot = :postal_code,
                 customer_notes = :customer_notes,
                 subtotal = :subtotal,
                 delivery_fee = :fee,
                 total_amount = :total,
                 required_deposit_amount = :deposit,
                 promo_discount_amount = :promo,
                 manual_discount_amount = :manual,
                 quantity_savings_snapshot = :savings,
                 updated_at = statement_timestamp()
             WHERE id = :id'
        );
        $statement->execute($values + ['id' => $orderId]);
    }

    /** Changes the status and records it in the history as a customer action. */
    public function changeStatus(int $orderId, string $previousStatus, string $newStatus, string $note): void
    {
        $statement = $this->connection->prepare(
            'UPDATE orders SET order_status = :status, updated_at = statement_timestamp() WHERE id = :id'
        );
        $statement->execute(['status' => $newStatus, 'id' => $orderId]);

        $statement = $this->connection->prepare(
            "INSERT INTO order_status_history (order_id, previous_status, new_status, change_source, change_note)
             VALUES (:id, :old, :new, 'CUSTOMER', :note)"
        );
        $statement->execute(['id' => $orderId, 'old' => $previousStatus, 'new' => $newStatus, 'note' => $note]);
    }
}
