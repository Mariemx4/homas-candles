<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Persistence repository for Customer Account Repository; it owns the SQL used to read and write this feature's data.
 *
 * DATA FLOW: Services call this layer with validated values. The repository talks to PDO and returns database records or persistence results; it does not render views.
 */

namespace App\Repositories;

use App\Database\TransactionManager;
use DomainException;
use PDO;
use RuntimeException;

// WHAT: every database operation for a customer account and their addresses.
// WHY:  pages/services call these methods instead of writing SQL directly —
//       this is the one place that knows the business rules around
//       addresses (max 3, always exactly one primary) that the database
//       schema itself can't enforce with a simple CHECK constraint.
final class CustomerAccountRepository
{
    public const MAX_ADDRESSES = 3;

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly PDO $connection,
        private readonly TransactionManager $transactions
    ) {
    }

    // WHAT: fetch the minimum account data needed to authenticate by email.
    // WHY:  login needs the password hash/status, but never needs addresses or
    //       the complete customer record in memory or in the session.
    public function findByEmail(string $email): ?array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'SELECT id, password_hash, account_status
             FROM customers_accounts WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $customer = $statement->fetch();

        return $customer === false ? null : $customer;
    }

    // WHAT: authenticate only against the required primary phone_number.
    // WHY:  second_phone_number is contact information, not a login identity.
    public function findByPhoneNumber(string $phoneNumber): ?array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'SELECT id, password_hash, account_status
             FROM customers_accounts WHERE phone_number = :phone_number LIMIT 1'
        );
        $statement->execute(['phone_number' => $phoneNumber]);
        $customer = $statement->fetch();

        return $customer === false ? null : $customer;
    }

    // WHAT: check whether an optional secondary contact number is already used.
    // WHY:  the database has a unique index for this column, but checking here
    //       lets signup show a helpful field message before attempting INSERT.
    public function findBySecondPhoneNumber(string $phoneNumber): ?array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'SELECT id FROM customers_accounts
             WHERE second_phone_number = :phone_number LIMIT 1'
        );
        $statement->execute(['phone_number' => $phoneNumber]);
        $customer = $statement->fetch();

        return $customer === false ? null : $customer;
    }

    /**
     * FUNCTION PURPOSE: Update the stored last login values.
     */
    public function updateLastLogin(int $customerId): void
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'UPDATE customers_accounts SET last_login_at = CURRENT_TIMESTAMP WHERE id = :customer_id'
        );
        $statement->execute(['customer_id' => $customerId]);
    }

    /**
     * FUNCTION PURPOSE: Update the stored password hash values.
     */
    public function updatePasswordHash(int $customerId, string $passwordHash): void
    {
        // WHAT: replace only the stored hash after a reset code is verified.
        // WHY:  repositories receive hashes, never plain passwords.
        $statement = $this->connection->prepare(
            'UPDATE customers_accounts
             SET password_hash = :password_hash
             WHERE id = :customer_id'
        );
        $statement->execute([
            'password_hash' => $passwordHash,
            'customer_id' => $customerId,
        ]);
    }

    /**
     * The service/controller must validate fields and create password_hash first.
     * Creating the account and its required first address is one transaction.
     */
    // WHAT: signup — creates the account row and its first (primary) address.
    // WHY:  wrapped in one transaction so a crash mid-way never leaves a
    //       customer account with zero addresses.
    public function createWithPrimaryAddress(array $account, array $address): int
    {
        // TRANSACTION IDIOM: Run related writes as one unit; an exception rolls back every write in this closure.
        return $this->transactions->run(function () use ($account, $address): int {
            // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
            $statement = $this->connection->prepare(
                'INSERT INTO customers_accounts
                    (first_name, last_name, email, phone_number,
                     second_phone_number, password_hash)
                 VALUES
                    (:first_name, :last_name, :email, :phone_number,
                     :second_phone_number, :password_hash)'
            );
            $statement->execute([
                'first_name' => $account['first_name'],
                'last_name' => $account['last_name'],
                'email' => $account['email'],
                'phone_number' => $account['phone_number'],
                'second_phone_number' => $account['second_phone_number'] ?? null,
                'password_hash' => $account['password_hash'],
            ]);

            $customerId = (int) $this->connection->lastInsertId();
            $this->insertAddress($customerId, $address, true);

            return $customerId;
        });
    }

    // WHAT: adds another address to an existing customer (up to MAX_ADDRESSES).
    // WHY:  lockCustomer() below takes a row lock first so two simultaneous
    //       "add address" requests for the same customer can't both pass the
    //       count check and end up with 4+ addresses.
    public function addAddress(int $customerId, array $address, bool $makePrimary = false): int
    {
        // TRANSACTION IDIOM: Run related writes as one unit; an exception rolls back every write in this closure.
        return $this->transactions->run(function () use ($customerId, $address, $makePrimary): int {
            $this->lockCustomer($customerId);

            // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
            $countStatement = $this->connection->prepare(
                'SELECT COUNT(*) FROM customer_addresses WHERE customer_id = :customer_id'
            );
            $countStatement->execute(['customer_id' => $customerId]);
            $addressCount = (int) $countStatement->fetchColumn();

            if ($addressCount >= self::MAX_ADDRESSES) {
                throw new DomainException('A customer may have at most three addresses.');
            }

            if ($makePrimary || $addressCount === 0) {
                $this->clearPrimaryAddress($customerId);
                $makePrimary = true;
            }

            return $this->insertAddress($customerId, $address, $makePrimary);
        });
    }

    // WHAT: makes one existing address the customer's primary one.
    // WHY:  clearPrimaryAddress() runs first so exactly one address ends up
    //       marked primary — the database schema alone can't guarantee that
    //       (see the note on customer_addresses.is_primary in migration 004).
    public function setPrimaryAddress(int $customerId, int $addressId): void
    {
        // TRANSACTION IDIOM: Run related writes as one unit; an exception rolls back every write in this closure.
        $this->transactions->run(function () use ($customerId, $addressId): void {
            $this->lockCustomer($customerId);
            $this->assertAddressOwnership($customerId, $addressId);
            $this->clearPrimaryAddress($customerId);

            // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
            $statement = $this->connection->prepare(
                'UPDATE customer_addresses SET is_primary = 1
                 WHERE id = :address_id AND customer_id = :customer_id'
            );
            $statement->execute([
                'address_id' => $addressId,
                'customer_id' => $customerId,
            ]);
        });
    }

    // WHAT: deletes an address, refusing to leave the customer with zero.
    // WHY:  if the deleted address was the primary one, promotes the
    //       customer's oldest remaining address to primary so that
    //       invariant ("always exactly one primary") never breaks.
    public function deleteAddress(int $customerId, int $addressId): void
    {
        // TRANSACTION IDIOM: Run related writes as one unit; an exception rolls back every write in this closure.
        $this->transactions->run(function () use ($customerId, $addressId): void {
            $this->lockCustomer($customerId);
            $address = $this->assertAddressOwnership($customerId, $addressId);

            // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
            $countStatement = $this->connection->prepare(
                'SELECT COUNT(*) FROM customer_addresses WHERE customer_id = :customer_id'
            );
            $countStatement->execute(['customer_id' => $customerId]);
            if ((int) $countStatement->fetchColumn() <= 1) {
                throw new DomainException('A customer account must keep at least one address.');
            }

            // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
            $deleteStatement = $this->connection->prepare(
                'DELETE FROM customer_addresses
                 WHERE id = :address_id AND customer_id = :customer_id'
            );
            $deleteStatement->execute([
                'address_id' => $addressId,
                'customer_id' => $customerId,
            ]);

            if ((int) $address['is_primary'] === 1) {
                // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
                $promoteStatement = $this->connection->prepare(
                    'UPDATE customer_addresses SET is_primary = 1
                     WHERE customer_id = :customer_id
                     ORDER BY id ASC LIMIT 1'
                );
                $promoteStatement->execute(['customer_id' => $customerId]);
            }
        });
    }

    /**
     * FUNCTION PURPOSE: Insert address as a new database record.
     */
    private function insertAddress(int $customerId, array $address, bool $isPrimary): int
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'INSERT INTO customer_addresses
                (customer_id, address_label, street_address, delegation,
                 governorate, postal_code, is_primary)
             VALUES
                (:customer_id, :address_label, :street_address, :delegation,
                 :governorate, :postal_code, :is_primary)'
        );
        $statement->execute([
            'customer_id' => $customerId,
            'address_label' => $address['address_label'] ?? null,
            'street_address' => $address['street_address'],
            'delegation' => $address['delegation'],
            'governorate' => $address['governorate'],
            'postal_code' => $address['postal_code'],
            'is_primary' => $isPrimary ? 1 : 0,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    // WHAT: locks the customer's row for the rest of this transaction.
    // WHY:  `FOR UPDATE` stops a second, simultaneous request for the same
    //       customer from reading/changing addresses until this transaction
    //       commits — this is what makes the MAX_ADDRESSES check above safe
    //       under concurrent requests.
    private function lockCustomer(int $customerId): void
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'SELECT id FROM customers_accounts WHERE id = :customer_id FOR UPDATE'
        );
        $statement->execute(['customer_id' => $customerId]);

        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('Customer account not found.');
        }
    }

    // WHAT: confirms an address ID actually belongs to this customer.
    // WHY:  stops one customer from editing/deleting another customer's
    //       address just by guessing an address ID.
    private function assertAddressOwnership(int $customerId, int $addressId): array
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'SELECT id, is_primary FROM customer_addresses
             WHERE id = :address_id AND customer_id = :customer_id FOR UPDATE'
        );
        $statement->execute([
            'address_id' => $addressId,
            'customer_id' => $customerId,
        ]);
        $address = $statement->fetch();

        if ($address === false) {
            throw new RuntimeException('Customer address not found.');
        }

        return $address;
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `clearPrimaryAddress` (clear primary address) and return its result.
     */
    private function clearPrimaryAddress(int $customerId): void
    {
        // SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.
        $statement = $this->connection->prepare(
            'UPDATE customer_addresses SET is_primary = 0 WHERE customer_id = :customer_id'
        );
        $statement->execute(['customer_id' => $customerId]);
    }
}
