<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Auth\GoogleIdentity;
use PDO;

/** Provider subjects identify logins; mutable customer emails never do. */
final class GoogleCustomerRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function lockSubject(string $subject): void
    {
        $statement = $this->db->prepare('SELECT pg_advisory_xact_lock(hashtextextended(CAST(:subject AS text), 0))');
        $statement->execute(['subject' => 'google:' . $subject]);
    }

    public function findBySubject(string $subject, bool $lock = false): ?array
    {
        $statement = $this->db->prepare('SELECT c.id, c.email, c.account_status FROM customer_google_identities g
            JOIN customers_accounts c ON c.id=g.customer_id WHERE g.google_subject=:subject' . ($lock ? ' FOR UPDATE OF c' : ''));
        $statement->execute(['subject' => $subject]);
        return $statement->fetch() ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->db->prepare('SELECT id, email, account_status FROM customers_accounts WHERE email=:email');
        $statement->execute(['email' => $email]);
        return $statement->fetch() ?: null;
    }

    public function findCustomer(int $customer): ?array
    {
        $statement = $this->db->prepare('SELECT id, email, account_status FROM customers_accounts WHERE id=:id FOR UPDATE');
        $statement->execute(['id' => $customer]);
        return $statement->fetch() ?: null;
    }

    public function subjectForCustomer(int $customer): ?string
    {
        $statement = $this->db->prepare('SELECT google_subject FROM customer_google_identities WHERE customer_id=:id');
        $statement->execute(['id' => $customer]);
        $subject = $statement->fetchColumn();
        return $subject === false ? null : (string) $subject;
    }

    public function attach(int $customer, GoogleIdentity $identity): void
    {
        $statement = $this->db->prepare('INSERT INTO customer_google_identities(google_subject,customer_id,email_at_link)
            VALUES(:subject,:customer,:email)');
        $statement->execute(['subject' => $identity->subject, 'customer' => $customer, 'email' => $identity->email]);
    }

    public function recordSignIn(string $subject): void
    {
        $statement = $this->db->prepare("UPDATE customer_google_identities SET last_used_at=(statement_timestamp() AT TIME ZONE 'UTC') WHERE google_subject=:subject");
        $statement->execute(['subject' => $subject]);
    }
}
