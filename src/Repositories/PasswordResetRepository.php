<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Persistence repository for Password Reset Repository; it owns the SQL used to read and write this feature's data.
 *
 * DATA FLOW: Services call this layer with validated values. The repository talks to PDO and returns database records or persistence results; it does not render views.
 */

namespace App\Repositories;

use App\Database\TransactionManager;
use PDO;

// WHAT: database operations for short-lived customer password reset codes.
// WHY:  SQL remains outside public pages/services, just like customer account SQL.
final class PasswordResetRepository
{
    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly PDO $connection,
        private readonly TransactionManager $transactions
    ) {
    }

    /**
     * FUNCTION PURPOSE: Answer whether recent request exists or is present.
     */
    public function hasRecentRequest(int $customerId, int $cooldownSeconds): bool
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM customer_password_reset_codes
             WHERE customer_id = :customer_id
               AND created_at > DATE_SUB(CURRENT_TIMESTAMP, INTERVAL :seconds SECOND)'
        );
        $statement->bindValue('customer_id', $customerId, PDO::PARAM_INT);
        $statement->bindValue('seconds', $cooldownSeconds, PDO::PARAM_INT);
        $statement->execute();
        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * FUNCTION PURPOSE: Create the supplied value and return its resulting data.
     */
    public function create(int $customerId, string $codeHash): int
    {
        // TRANSACTION IDIOM: Run related writes as one unit; an exception rolls back every write in this closure.
        return $this->transactions->run(function () use ($customerId, $codeHash): int {
            // A newly requested code revokes every older unused code.
            $revoke = $this->connection->prepare(
                'UPDATE customer_password_reset_codes
                 SET used_at = CURRENT_TIMESTAMP
                 WHERE customer_id = :customer_id AND used_at IS NULL'
            );
            $revoke->execute(['customer_id' => $customerId]);

            // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
            $statement = $this->connection->prepare(
                'INSERT INTO customer_password_reset_codes
                    (customer_id, code_hash, expires_at, attempts_remaining)
                 VALUES (:customer_id, :code_hash,
                         DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 10 MINUTE), 5)'
            );
            $statement->execute([
                'customer_id' => $customerId,
                'code_hash' => $codeHash,
            ]);
            return (int) $this->connection->lastInsertId();
        });
    }

    /**
     * FUNCTION PURPOSE: Fetch latest usable for update and return the matching data when it exists.
     */
    public function findLatestUsableForUpdate(int $customerId): ?array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
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

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `decrementAttempts` (decrement attempts) and return its result.
     */
    public function decrementAttempts(int $resetId): void
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'UPDATE customer_password_reset_codes
             SET attempts_remaining = GREATEST(attempts_remaining - 1, 0)
             WHERE id = :id'
        );
        $statement->execute(['id' => $resetId]);
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `markUsed` (mark used) and return its result.
     */
    public function markUsed(int $resetId): void
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'UPDATE customer_password_reset_codes
             SET used_at = CURRENT_TIMESTAMP WHERE id = :id'
        );
        $statement->execute(['id' => $resetId]);
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `revoke` (revoke) and return its result.
     */
    public function revoke(int $resetId): void
    {
        $this->markUsed($resetId);
    }
}
