<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class AdminDashboardRepository
{
    // Only these order states represent work that is not yet fulfilled. The
    // constant gives findUpcomingOrders one authoritative list to bind to SQL.
    private const INCOMPLETE_ORDER_STATUSES = [
        'REQUESTED',
        'CONFIRMED',
        'PREPARING',
        'PREPARED',
        'WAITING_FOR_PICKUP',
        'IN_DELIVERY',
    ];

    // Converted, closed, and cancelled requests are intentionally absent.
    private const OPEN_REQUEST_STATUSES = [
        'SUBMITTED',
        'CONTACTED',
        'DISCUSSING',
        'AGREED',
    ];

    /** Store the shared PDO database connection created during bootstrap. */
    public function __construct(private readonly PDO $connection)
    {
    }

    /** Count newly requested orders that still need administrator review. */
    public function countRequestedOrders(): int
    {
        return $this->count(
            "SELECT COUNT(*) FROM active_orders AS orders WHERE order_status = 'REQUESTED'"
        );
    }

    /** Count unfinished orders whose required date falls in the given window. */
    public function countOrdersDueSoon(string $today, string $throughDate): int
    {
        // Named placeholders keep date values separate from SQL text. PDO
        // safely sends them to the database when execute() is called.
        $statement = $this->connection->prepare(
            'SELECT COUNT(*)
             FROM active_orders AS orders
             WHERE required_date BETWEEN :today AND :through_date
               AND order_status NOT IN (\'PICKED_UP\', \'DELIVERED\', \'CANCELLED\')'
        );
        $statement->execute(['today' => $today, 'through_date' => $throughDate]);

        return (int) $statement->fetchColumn();
    }

    /** Count payments waiting for an administrator verification decision. */
    public function countPendingPayments(): int
    {
        return $this->count(
            "SELECT COUNT(*) FROM payments WHERE payment_status = 'PENDING_VERIFICATION'"
        );
    }

    /** Count custom requests that are still being discussed or agreed. */
    public function countOpenRequests(): int
    {
        return $this->count(
            "SELECT COUNT(*)
             FROM custom_product_requests
             WHERE archived_at IS NULL AND request_status IN ('SUBMITTED', 'CONTACTED', 'DISCUSSING', 'AGREED')"
        );
    }

    public function countLowStockMaterials(): int
    {
        return $this->count('SELECT COUNT(*) FROM materials AS material WHERE ' . MaterialRepository::LOW_STOCK_CONDITION);
    }

    public function countUnconfiguredMaterials(): int
    {
        return $this->count('SELECT COUNT(*) FROM materials WHERE is_active = 1 AND tracks_stock = 1 AND minimum_stock_level IS NULL');
    }

    public function findProductionAttention(): array
    {
        $rows = $this->connection->query("SELECT id,order_number,required_date,order_status FROM active_orders WHERE order_status IN ('CONFIRMED','PREPARING') ORDER BY required_date,id LIMIT 8")->fetchAll();
        $checks = (new ProductionReadinessRepository($this->connection))->forOrders(array_column($rows, 'id'));
        foreach ($rows as &$row) {
            $row['readiness'] = $checks[(int)$row['id']];
        }
        return $rows;
    }

    /**
     * Return materials below their own threshold; quantities across units are never ranked.
     *
     * @return list<array<string, mixed>> Each array is one material row.
     */
    public function findLowStockMaterials(int $limit = 5): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, sku, name, unit, stock_quantity, minimum_stock_level
             FROM materials AS material
             WHERE ' . MaterialRepository::LOW_STOCK_CONDITION . '
             ORDER BY name ASC, id ASC
             LIMIT :result_limit'
        );
        // LIMIT must be bound as an integer. safeLimit() prevents invalid or
        // unexpectedly huge dashboard queries.
        $statement->bindValue('result_limit', $this->safeLimit($limit), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * Find unfinished orders that are overdue, due today, or due soon.
     *
     * @return list<array<string, mixed>> Rows ordered by operational urgency.
     */
    public function findUrgentOrders(string $today, string $throughDate, int $limit = 8): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, order_number, customer_name_snapshot, required_date,
                    delivery_method, total_amount, order_status
             FROM active_orders AS orders
             WHERE required_date <= :through_date
               AND order_status NOT IN (\'PICKED_UP\', \'DELIVERED\', \'CANCELLED\')
             ORDER BY
               CASE WHEN required_date < :today THEN 0 ELSE 1 END,
               required_date ASC,
               created_at ASC,
               id ASC
             LIMIT :result_limit'
        );
        $statement->bindValue('today', $today);
        $statement->bindValue('through_date', $throughDate);
        $statement->bindValue('result_limit', $this->safeLimit($limit), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * Find the nearest future orders whose status still requires work.
     *
     * @return list<array<string, mixed>> Upcoming order summaries.
     */
    public function findUpcomingOrders(string $today, int $limit = 6): array
    {
        // SQL needs one `?` placeholder for every allowed status. The final SQL
        // becomes `IN (?, ?, ...)`; values are still bound separately below.
        $placeholders = implode(', ', array_fill(0, count(self::INCOMPLETE_ORDER_STATUSES), '?'));
        $statement = $this->connection->prepare(
            "SELECT id, order_number, customer_name_snapshot, required_date,
                    delivery_method, total_amount, order_status
             FROM active_orders AS orders
             WHERE required_date >= ?
               AND order_status IN ({$placeholders})
             ORDER BY required_date ASC, created_at ASC, id ASC
             LIMIT ?"
        );

        // Positional PDO placeholders start at 1, not 0. Incrementing `$position`
        // keeps every value aligned with its matching question mark.
        $position = 1;
        $statement->bindValue($position++, $today);
        foreach (self::INCOMPLETE_ORDER_STATUSES as $status) {
            $statement->bindValue($position++, $status);
        }
        $statement->bindValue($position, $this->safeLimit($limit), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * Return the most recently created custom requests that still need action.
     *
     * @return list<array<string, mixed>> Open request summaries, newest first.
     */
    public function findRecentOpenRequests(int $limit = 5): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::OPEN_REQUEST_STATUSES), '?'));
        $statement = $this->connection->prepare(
            "SELECT requests.id, request_number, customer_name_snapshot, required_date,
                    request_status, requests.created_at, customer_message, follow_up.next_follow_up_at
             FROM custom_product_requests requests
             LEFT JOIN LATERAL (SELECT attempts.next_follow_up_at FROM request_contact_attempts attempts
                 WHERE attempts.request_id=requests.id ORDER BY attempts.contacted_at DESC,attempts.id DESC LIMIT 1) follow_up ON TRUE
             WHERE requests.archived_at IS NULL AND request_status IN ({$placeholders})
             ORDER BY CASE WHEN follow_up.next_follow_up_at IS NOT NULL AND follow_up.next_follow_up_at <= CURRENT_TIMESTAMP THEN 0 WHEN request_status='SUBMITTED' THEN 1 ELSE 2 END, follow_up.next_follow_up_at ASC NULLS LAST, requests.created_at ASC, requests.id ASC
             LIMIT ?"
        );

        $position = 1;
        foreach (self::OPEN_REQUEST_STATUSES as $status) {
            $statement->bindValue($position++, $status);
        }
        $statement->bindValue($position, $this->safeLimit($limit), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * Return the oldest payments waiting for verification.
     *
     * The INNER JOIN supplies the human-readable order number stored in the
     * orders table while the payment row stores only `order_id`.
     *
     * @return list<array<string, mixed>> Pending payment summaries.
     */
    public function findPendingPayments(int $limit = 5): array
    {
        $statement = $this->connection->prepare(
            "SELECT payments.id, payments.order_id, orders.order_number, payments.payment_type,
                    payments.amount, payments.currency, payments.payment_status,
                    payments.created_at
             FROM payments
             INNER JOIN orders ON orders.id = payments.order_id
             WHERE payments.payment_status = 'PENDING_VERIFICATION'
             ORDER BY payments.created_at ASC, payments.id ASC
             LIMIT :result_limit"
        );
        $statement->bindValue('result_limit', $this->safeLimit($limit), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** Execute a trusted fixed COUNT query and convert PDO's string result. */
    private function count(string $sql): int
    {
        return (int) $this->connection->query($sql)->fetchColumn();
    }

    /** Clamp list sizes to the safe inclusive range 1..50. */
    private function safeLimit(int $limit): int
    {
        return max(1, min($limit, 50));
    }
}
