<?php

declare(strict_types=1);

namespace App\Auth;

use App\Database\TransactionManager;
use App\Repositories\CustomerAccountRepository;
use App\Repositories\GoogleCustomerRepository;
use DomainException;
use PDOException;

/** New customers complete registration; existing customers prove ownership before linking. */
final class GoogleCustomerService
{
    public function __construct(
        private readonly GoogleCustomerRepository $identities,
        private readonly CustomerAccountRepository $customers,
        private readonly TransactionManager $transactions,
        private readonly CustomerRegistrationValidator $validator
    ) {
    }

    public function resolve(GoogleIdentity $identity): array
    {
        return $this->transactions->run(function () use ($identity): array {
            $this->identities->lockSubject($identity->subject);
            $linked = $this->identities->findBySubject($identity->subject, true);
            if ($linked !== null) {
                $this->requireActive($linked);
                $this->recordSignIn((int) $linked['id'], $identity);
                return ['customer_id' => (int) $linked['id']];
            }
            $existing = $this->identities->findByEmail($identity->email);
            return $this->resolveExisting($identity, $existing);
        });
    }

    public function register(GoogleIdentity $identity, array $input): array
    {
        // Email and subject come only from the server-side verified identity.
        [$clean, $errors] = $this->validator->validate(array_replace($input, ['email' => $identity->email]), false);
        if ($errors !== []) {
            return ['errors' => $errors];
        }
        try {
            return $this->transactions->run(function () use ($identity, $clean): array {
                $this->identities->lockSubject($identity->subject);
                $linked = $this->identities->findBySubject($identity->subject, true);
                if ($linked !== null) {
                    $this->requireActive($linked);
                    $this->recordSignIn((int) $linked['id'], $identity);
                    return ['errors' => [], 'customer_id' => (int) $linked['id']];
                }
                $existing = $this->identities->findByEmail($identity->email);
                if ($existing !== null) {
                    return ['errors' => [], ...$this->resolveExisting($identity, $existing)];
                }
                $errors = [];
                if ($this->customers->findByPhoneNumber($clean['phone_number']) !== null) {
                    $errors['phone_number'] = 'google.phone_used';
                }
                if ($clean['second_phone_number'] !== '' && $this->customers->findBySecondPhoneNumber($clean['second_phone_number']) !== null) {
                    $errors['second_phone_number'] = 'google.phone_used';
                }
                if ($errors !== []) {
                    return ['errors' => $errors];
                }
                // The legacy schema requires a hash. An unknowable random secret
                // prevents password login until the owner uses email password reset.
                $customer = $this->customers->createWithPrimaryAddress([
                    'first_name' => $clean['first_name'], 'last_name' => $clean['last_name'], 'email' => $identity->email,
                    'phone_number' => $clean['phone_number'],
                    'second_phone_number' => $clean['second_phone_number'] ?: null,
                    'password_hash' => PasswordPolicy::hashNew(bin2hex(random_bytes(32)) . 'A1'),
                ], [
                    'address_label' => $clean['address_label'] ?: null, 'street_address' => $clean['street_address'],
                    'governorate' => $clean['governorate'], 'delegation' => $clean['delegation'], 'postal_code' => $clean['postal_code'],
                ]);
                $this->identities->attach($customer, $identity);
                $this->recordSignIn($customer, $identity);
                return ['errors' => [], 'customer_id' => $customer];
            });
        } catch (PDOException $error) {
            if ($error->getCode() !== '23505') {
                throw $error;
            }
            // Unique constraints settle registration races; the outer transaction
            // has already rolled back account, address and provider link together.
            $resolved = $this->resolve($identity);
            return isset($resolved['customer_id']) || isset($resolved['link_customer_id'])
                ? ['errors' => [], ...$resolved] : ['errors' => ['phone_number' => 'google.phone_used']];
        }
    }

    public function link(int $customer, GoogleIdentity $identity): void
    {
        $this->transactions->run(function () use ($customer, $identity): void {
            $this->identities->lockSubject($identity->subject);
            $this->attachToCustomer($customer, $identity);
        });
    }

    /** Called inside the subject-locked transaction; never trusts submitted email or domain. */
    private function resolveExisting(GoogleIdentity $identity, ?array $existing): array
    {
        if ($existing === null) {
            return ['customer_id' => null, 'link_customer_id' => null];
        }
        $customer = (int) $existing['id'];
        if (!$identity->ownsEmailAddress()) {
            return ['customer_id' => null, 'link_customer_id' => $customer];
        }
        // Google's verified Gmail/Workspace ownership replaces the old-password
        // challenge. Reuse the existing customer ID, address, orders and password.
        $this->attachToCustomer($customer, $identity);
        return ['customer_id' => $customer];
    }

    /** Shared by authoritative Google matching and locally authenticated explicit linking. */
    private function attachToCustomer(int $customer, GoogleIdentity $identity): void
    {
        // Lock and re-read: a concurrent profile edit or another Google connection
        // must not make an earlier email lookup authorize the wrong association.
        $account = $this->identities->findCustomer($customer);
        if ($account === null || strcasecmp((string) $account['email'], $identity->email) !== 0) {
            throw new DomainException('google.link_failed');
        }
        $this->requireActive($account);
        $linked = $this->identities->findBySubject($identity->subject);
        $existingSubject = $this->identities->subjectForCustomer($customer);
        if (($linked !== null && (int) $linked['id'] !== $customer)
            || ($existingSubject !== null && $existingSubject !== $identity->subject)) {
            throw new DomainException('google.link_failed');
        }
        if ($linked === null) {
            $this->identities->attach($customer, $identity);
        }
        $this->recordSignIn($customer, $identity);
    }

    private function recordSignIn(int $customer, GoogleIdentity $identity): void
    {
        $this->customers->updateLastLogin($customer);
        $this->identities->recordSignIn($identity->subject);
    }

    private function requireActive(array $account): void
    {
        if ($account['account_status'] !== 'ACTIVE') {
            throw new DomainException('google.account_unavailable');
        }
    }
}
