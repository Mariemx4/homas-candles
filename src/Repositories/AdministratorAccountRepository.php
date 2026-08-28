<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class AdministratorAccountRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function findByEmail(string $email): ?array
    {
        return $this->findForLogin('email', $email);
    }

    public function findByPhoneNumber(string $phoneNumber): ?array
    {
        return $this->findForLogin('phone_number', $phoneNumber);
    }

    public function findForAuthorization(int $administratorId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, first_name, last_name, email, account_status
             FROM administrators_accounts WHERE id = :administrator_id LIMIT 1'
        );
        $statement->execute(['administrator_id' => $administratorId]);
        $administrator = $statement->fetch();

        return $administrator === false ? null : $administrator;
    }

    public function updateLastLogin(int $administratorId): void
    {
        $statement = $this->connection->prepare(
            'UPDATE administrators_accounts
             SET last_login_at = CURRENT_TIMESTAMP
             WHERE id = :administrator_id'
        );
        $statement->execute(['administrator_id' => $administratorId]);
    }

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
