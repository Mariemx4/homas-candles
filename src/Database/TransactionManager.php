<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use Throwable;

// WHAT: wraps a block of database calls so they all succeed or all roll back.
// WHY:  a repository method like "create a customer AND their first address"
//       is two separate INSERTs — without a transaction, a crash between
//       them would leave a customer with no address. Every repository
//       (see src/Repositories/*.php) uses this instead of calling
//       beginTransaction()/commit() by hand.
final class TransactionManager
{
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * Run one complete business operation atomically.
     *
     * @template TResult
     * @param callable(PDO): TResult $operation
     * @return TResult
     */
    public function run(callable $operation): mixed
    {
        // WHAT: if we're already inside a transaction, just run the code —
        //       don't try to start a nested one.
        // WHY:  MySQL/PDO doesn't support real nested transactions; this
        //       lets one repository method safely call another repository
        //       method that also uses ->run(), without double-wrapping.
        if ($this->connection->inTransaction()) {
            return $operation($this->connection);
        }

        $this->connection->beginTransaction();

        try {
            $result = $operation($this->connection);
            $this->connection->commit();

            return $result;
        } catch (Throwable $exception) {
            // WHAT: undo every change made so far in this operation.
            // WHY:  whatever went wrong (a thrown validation error, a DB
            //       error...), we never want half of the operation to stick.
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }
}
