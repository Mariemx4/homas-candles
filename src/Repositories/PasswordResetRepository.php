<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\TransactionManager;
use PDO;

// WHAT: database operations for short-lived customer password reset codes.
// WHY:  SQL remains outside public pages/services, just like customer account SQL.
final class PasswordResetRepository
{
    public function __construct(
        private readonly PDO $connection,
        private readonly TransactionManager $transactions
    ) {
    }


    public function hasRecentRequest(int $customerId, int $cooldownSeconds): bool
    {
        $statement = $this->connection->prepare(
            "SELECT COUNT(*) FROM customer_password_reset_codes
             WHERE customer_id = :customer_id
               AND created_at > CURRENT_TIMESTAMP - (CAST(:seconds AS integer) * INTERVAL '1 second')"
        );
        $statement->bindValue('customer_id', $customerId, PDO::PARAM_INT);
        $statement->bindValue('seconds', $cooldownSeconds, PDO::PARAM_INT);
        $statement->execute();
        return (int) $statement->fetchColumn() > 0;
    }


    public function create(int $customerId, string $codeHash): int
    {
        return $this->transactions->run(function () use ($customerId, $codeHash): int {
            // A newly requested code revokes every older unused code.
            $revoke = $this->connection->prepare(
                'UPDATE customer_password_reset_codes
                 SET used_at = CURRENT_TIMESTAMP
                 WHERE customer_id = :customer_id AND used_at IS NULL'
            );
            $revoke->execute(['customer_id' => $customerId]);
            $statement = $this->connection->prepare(
                "INSERT INTO customer_password_reset_codes
                    (customer_id, code_hash, expires_at, attempts_remaining)
                 VALUES (:customer_id, :code_hash,
                         CURRENT_TIMESTAMP + INTERVAL '10 minutes', 5)"
            );
            $statement->execute([
                'customer_id' => $customerId,
                'code_hash' => $codeHash,
            ]);
            return (int) $this->connection->lastInsertId();
        });
    }


    public function findLatestUsableForUpdate(int $customerId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, code_hash, expires_at, attempts_remaining,
                    (expires_at <= CURRENT_TIMESTAMP) AS is_expired
             FROM customer_password_reset_codes
             WHERE customer_id = :customer_id AND used_at IS NULL
             ORDER BY id DESC LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['customer_id' => $customerId]);
        $reset = $statement->fetch();
        return $reset === false ? null : $reset;
    }

    public function decrementAttempts(int $resetId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE customer_password_reset_codes
             SET attempts_remaining = GREATEST(attempts_remaining - 1, 0)
             WHERE id = :id'
        );
        $statement->execute(['id' => $resetId]);
    }

    public function markUsed(int $resetId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE customer_password_reset_codes
             SET used_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute(['id' => $resetId]);
    }

    public function revoke(int $resetId): void
    {
        $this->markUsed($resetId);
    }
}
