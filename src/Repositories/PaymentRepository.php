<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Services\Money;
use PDO;

final class PaymentRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /** Counts are operational records, independent of the current list filters. */
    public function overview(): array
    {
        $statement = $this->connection->query("SELECT
            COUNT(*) FILTER (WHERE payment_method = 'D17' AND payment_status = 'PENDING_VERIFICATION') AS pending,
            COUNT(*) FILTER (WHERE payment_status = 'VERIFIED') AS verified
            FROM payments");
        return $statement->fetch();
    }

    /** Customer ownership is explicit; guest access comes only from server-side checkout session IDs. */
    public function customerCanAccess(int $orderId, ?int $customerId, array $sessionOrderIds): bool
    {
        $statement = $this->connection->prepare('SELECT customer_id FROM orders WHERE id = :id AND deleted_at IS NULL');
        $statement->execute(['id' => $orderId]);
        $order = $statement->fetch();
        return $order !== false && (($customerId !== null && (int) $order['customer_id'] === $customerId)
            || in_array($orderId, $sessionOrderIds, true));
    }


    public function countForList(array $criteria): int
    {
        [$where, $parameters] = $this->listWhere($criteria);
        $statement = $this->connection->prepare(
            "SELECT COUNT(*) FROM payments INNER JOIN orders ON orders.id = payments.order_id {$where}"
        );
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }


    public function findForList(array $criteria, int $offset, int $limit): array
    {
        [$where, $parameters] = $this->listWhere($criteria);
        $statement = $this->connection->prepare(
            "SELECT payments.id, payments.payment_method, payments.payment_type,
                    payments.payment_status, payments.amount, payments.currency,
                    payments.created_at, orders.id AS order_id, orders.order_number,
                    orders.customer_name_snapshot, orders.deleted_at,
                    orders.required_deposit_amount, orders.total_amount,
                    COALESCE((SELECT SUM(paid.amount) FROM payments paid WHERE paid.order_id = orders.id AND paid.payment_status = 'VERIFIED'), 0.000) AS verified_amount,
                    (payments.payment_proof_path IS NOT NULL) AS has_evidence
             FROM payments
             INNER JOIN orders ON orders.id = payments.order_id
             {$where}
             ORDER BY payments.created_at DESC, payments.id DESC
             LIMIT :result_limit OFFSET :result_offset"
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue('result_limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $statement->bindValue('result_offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }


    public function findSummaryForOrder(int $orderId): ?array
    {
        $statement = $this->connection->prepare(
            "SELECT orders.id, orders.order_number, orders.order_status,
                    orders.customer_name_snapshot, orders.total_amount,
                    orders.required_deposit_amount, orders.delivery_method, orders.deleted_at,
                    COALESCE(SUM(CASE WHEN payments.payment_status = 'VERIFIED' THEN payments.amount ELSE 0 END), 0.000) AS verified_amount,
                    COALESCE(SUM(CASE WHEN payments.payment_status = 'PENDING_VERIFICATION' THEN payments.amount ELSE 0 END), 0.000) AS pending_amount,
                    COALESCE(SUM(CASE WHEN payments.payment_status = 'REJECTED' THEN payments.amount ELSE 0 END), 0.000) AS rejected_amount
             FROM orders
             LEFT JOIN payments ON payments.order_id = orders.id
             WHERE orders.id = :order_id
             GROUP BY orders.id
             LIMIT 1"
        );
        $statement->execute(['order_id' => $orderId]);
        $summary = $statement->fetch();

        return $summary === false ? null : $summary;
    }


    public function findPaymentsForOrder(int $orderId): array
    {
        $statement = $this->connection->prepare(
            "SELECT payments.id, payments.payment_method, payments.payment_type,
                    payments.payment_status, payments.amount, payments.currency,
                    payments.d17_reference, payments.customer_declared_paid_at,
                    payments.verified_at, payments.rejected_at, payments.rejection_reason, payments.created_at,
                    (payments.payment_proof_path IS NOT NULL) AS has_evidence,
                    CASE WHEN reviewers.id IS NULL THEN NULL
                         ELSE CONCAT(reviewers.first_name, ' ', reviewers.last_name) END AS reviewer_name
             FROM payments
             LEFT JOIN administrators_accounts AS reviewers
               ON reviewers.id = payments.verified_by_administrator_id
             WHERE payments.order_id = :order_id
             ORDER BY payments.created_at DESC, payments.id DESC"
        );
        $statement->execute(['order_id' => $orderId]);

        return $statement->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lockOrder(int $orderId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, order_number, customer_id, deleted_at, order_status, total_amount, required_deposit_amount
             FROM orders
             WHERE id = :order_id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['order_id' => $orderId]);
        $order = $statement->fetch();

        return $order === false ? null : $order;
    }

    /**
     * @return array{verified: string, pending: string, rejected: string}
     */
    public function lockSumsForOrder(int $orderId): array
    {
        $statement = $this->connection->prepare(
            'SELECT payment_status, amount
             FROM payments
             WHERE order_id = :order_id
             FOR UPDATE'
        );
        $statement->execute(['order_id' => $orderId]);

        $millis = ['VERIFIED' => 0, 'PENDING_VERIFICATION' => 0, 'REJECTED' => 0];
        foreach ($statement->fetchAll() as $payment) {
            $status = (string) $payment['payment_status'];
            if (isset($millis[$status])) {
                $millis[$status] += Money::storedMillis((string) $payment['amount']);
            }
        }

        return [
            'verified' => Money::formatMillis($millis['VERIFIED']),
            'pending' => Money::formatMillis($millis['PENDING_VERIFICATION']),
            'rejected' => Money::formatMillis($millis['REJECTED']),
        ];
    }


    public function d17ReferenceExists(string $reference): bool
    {
        $statement = $this->connection->prepare(
            'SELECT 1 FROM payments WHERE d17_reference = :reference LIMIT 1'
        );
        $statement->execute(['reference' => $reference]);

        return $statement->fetchColumn() !== false;
    }


    public function insert(array $payment): int
    {
        $statement = $this->connection->prepare(
            'INSERT INTO payments
                (order_id, payment_method, payment_type, payment_status, amount, currency,
                 d17_reference, payment_proof_path, customer_declared_paid_at,
                 verified_at, verified_by_administrator_id, request_key)
             VALUES
                (:order_id, :payment_method, :payment_type, :payment_status, :amount, \'TND\',
                 :d17_reference, :payment_proof_path, :customer_declared_paid_at,
                 :verified_at, :verified_by_administrator_id, :request_key)'
        );
        $statement->execute($payment + ['request_key' => null]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lockPayment(int $paymentId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT payments.id, payments.order_id, payments.payment_method,
                    payments.payment_type, payments.payment_status, payments.amount,
                    payments.currency, payments.d17_reference, payments.customer_declared_paid_at,
                    payments.created_at, (payments.payment_proof_path IS NOT NULL) AS has_evidence,
                    orders.order_number, orders.customer_name_snapshot,
                    orders.required_deposit_amount, orders.total_amount, orders.deleted_at
             FROM payments
             INNER JOIN orders ON orders.id = payments.order_id
             WHERE payments.id = :payment_id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['payment_id' => $paymentId]);
        $payment = $statement->fetch();

        return $payment === false ? null : $payment;
    }


    public function findForConfirmation(int $paymentId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT payments.id, payments.order_id, payments.payment_method,
                    payments.payment_type, payments.payment_status, payments.amount,
                    payments.currency, payments.d17_reference, payments.customer_declared_paid_at,
                    payments.created_at, (payments.payment_proof_path IS NOT NULL) AS has_evidence,
                    orders.order_number, orders.customer_name_snapshot,
                    orders.required_deposit_amount, orders.total_amount, orders.deleted_at
             FROM payments
             INNER JOIN orders ON orders.id = payments.order_id
             WHERE payments.id = :payment_id
             LIMIT 1'
        );
        $statement->execute(['payment_id' => $paymentId]);
        $payment = $statement->fetch();

        return $payment === false ? null : $payment;
    }

    public function markVerified(int $paymentId, int $administratorId, string $verifiedAt): void
    {
        $statement = $this->connection->prepare(
            "UPDATE payments
             SET payment_status = 'VERIFIED',
                 verified_by_administrator_id = :administrator_id,
                 verified_at = :verified_at
             WHERE id = :payment_id
               AND payment_method = 'D17'
               AND payment_status = 'PENDING_VERIFICATION'"
        );
        $statement->execute([
            'administrator_id' => $administratorId,
            'verified_at' => $verifiedAt,
            'payment_id' => $paymentId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \DomainException('This payment was already reviewed. Refresh the page and try again.');
        }
    }

    public function markRejected(int $paymentId, int $administratorId, string $reason): void
    {
        $statement = $this->connection->prepare(
            "UPDATE payments
             SET payment_status = 'REJECTED',
                 verified_by_administrator_id = :administrator_id,
                 rejection_reason = :reason,
                 rejected_at = statement_timestamp()
             WHERE id = :payment_id
               AND payment_method = 'D17'
               AND payment_status = 'PENDING_VERIFICATION'"
        );
        $statement->execute([
            'administrator_id' => $administratorId,
            'reason' => $reason,
            'payment_id' => $paymentId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \DomainException('This payment was already reviewed. Refresh the page and try again.');
        }
    }


    public function findEvidence(int $paymentId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT order_id, payment_proof_path FROM payments WHERE id = :payment_id LIMIT 1'
        );
        $statement->execute(['payment_id' => $paymentId]);
        $payment = $statement->fetch();
        if ($payment === false) {
            return null;
        }

        return ['order_id' => (int) $payment['order_id'], 'path' => $payment['payment_proof_path']];
    }


    private function listWhere(array $criteria): array
    {
        $conditions = [];
        $parameters = [];

        if (($criteria['search'] ?? null) !== null) {
            $escaped = '%' . SqlFragments::escapeLike((string) $criteria['search']) . '%';
            $conditions[] = "(LOWER(orders.order_number) COLLATE \"C\" LIKE LOWER(:search_order_number) ESCAPE '='
                OR LOWER(orders.customer_name_snapshot) COLLATE \"C\" LIKE LOWER(:search_customer_name) ESCAPE '=')";
            $parameters['search_order_number'] = $escaped;
            $parameters['search_customer_name'] = $escaped;
        }
        if (($criteria['status'] ?? null) !== null) {
            $conditions[] = 'payments.payment_status = :payment_status';
            $parameters['payment_status'] = (string) $criteria['status'];
        }
        if (($criteria['method'] ?? null) !== null) {
            $conditions[] = 'payments.payment_method = :payment_method';
            $parameters['payment_method'] = (string) $criteria['method'];
        }
        if (($criteria['date_from'] ?? null) !== null) {
            $conditions[] = 'payments.created_at >= :date_from';
            $parameters['date_from'] = \App\Services\BusinessClock::utcBounds($criteria['date_from'], $criteria['date_from'])['start'];
        }
        if (($criteria['date_to'] ?? null) !== null) {
            $conditions[] = 'payments.created_at < :date_to';
            $parameters['date_to'] = \App\Services\BusinessClock::utcBounds($criteria['date_to'], $criteria['date_to'])['end'];
        }

        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $parameters];
    }




}
