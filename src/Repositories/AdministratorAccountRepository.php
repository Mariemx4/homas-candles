<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Persistence repository for Administrator Account Repository; it owns the SQL used to read and write this feature's data.
 *
 * DATA FLOW: Services call this layer with validated values. The repository talks to PDO and returns database records or persistence results; it does not render views.
 */

namespace App\Repositories;

use PDO;

final class AdministratorAccountRepository
{
    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(private readonly PDO $connection)
    {
    }

    /**
     * FUNCTION PURPOSE: Fetch by email and return the matching data when it exists.
     */
    public function findByEmail(string $email): ?array
    {
        return $this->findForLogin('email', $email);
    }

    /**
     * FUNCTION PURPOSE: Fetch by phone number and return the matching data when it exists.
     */
    public function findByPhoneNumber(string $phoneNumber): ?array
    {
        return $this->findForLogin('phone_number', $phoneNumber);
    }

    /**
     * FUNCTION PURPOSE: Fetch for authorization and return the matching data when it exists.
     */
    public function findForAuthorization(int $administratorId): ?array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'SELECT id, first_name, last_name, email, account_status
             FROM administrators_accounts WHERE id = :administrator_id LIMIT 1'
        );
        $statement->execute(['administrator_id' => $administratorId]);
        $administrator = $statement->fetch();

        return $administrator === false ? null : $administrator;
    }

    /**
     * FUNCTION PURPOSE: Update the stored last login values.
     */
    public function updateLastLogin(int $administratorId): void
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'UPDATE administrators_accounts
             SET last_login_at = CURRENT_TIMESTAMP
             WHERE id = :administrator_id'
        );
        $statement->execute(['administrator_id' => $administratorId]);
    }

    /** Upgrade a verified legacy hash without overwriting a concurrent password change. */
    public function upgradePasswordHashIfCurrent(int $administratorId, string $oldHash, string $newHash): bool
    {
        $statement = $this->connection->prepare(
            'UPDATE administrators_accounts SET password_hash = :new_hash
             WHERE id = :id AND password_hash COLLATE "C" = :old_hash'
        );
        $statement->execute(['new_hash' => $newHash, 'id' => $administratorId, 'old_hash' => $oldHash]);
        return $statement->rowCount() === 1;
    }

    /**
     * FUNCTION PURPOSE: Fetch for login and return the matching data when it exists.
     */
    private function findForLogin(string $column, string $value): ?array
    {
        // The column is selected only by the two private callers above; user
        // input is always a bound value and can never become SQL syntax.
        $statement = $this->connection->prepare(
            "SELECT id, password_hash, account_status
             FROM administrators_accounts WHERE {$column} = :identifier LIMIT 1"
        );
        $statement->execute(['identifier' => $value]);
        $administrator = $statement->fetch();

        return $administrator === false ? null : $administrator;
    }
}
