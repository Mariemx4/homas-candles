<?php

declare(strict_types=1);

namespace App\Auth;

use App\Repositories\CustomerAccountRepository;
use PDOException;

// WHAT: all customer authentication and registration business rules.
// WHY:  pages only collect input; this service normalizes it, validates it,
//       hashes/verifies passwords, and coordinates the repository + session.
final class CustomerAuthService
{
    public const GENERIC_LOGIN_ERROR = 'Unable to sign in. Check your details or try again later.';
    public const SUSPENDED_LOGIN_ERROR = self::GENERIC_LOGIN_ERROR;


    public function __construct(
        private readonly CustomerAccountRepository $customers,
        private readonly CustomerSession $session,
        private readonly array $governorates,
        private readonly LoginThrottle $throttle
    ) {
    }

    /**
     * @return array{errors: array<string, string>}
     */
    public function signIn(string $rawIdentifier, string $password, ?string $remoteAddress = null): array
    {
        $identifier = $this->normalizeLoginIdentifier($rawIdentifier);
        $customer = $identifier === null ? null : ($identifier['type'] === 'email'
            ? $this->customers->findByEmail($identifier['value'])
            : $this->customers->findByPhoneNumber($identifier['value']));
        $accountBucket = $this->throttle->accountBucket(
            $customer === null ? null : (int) $customer['id'],
            $identifier['value'] ?? $rawIdentifier
        );
        $ipBucket = $this->throttle->ipBucket($remoteAddress ?? ($_SERVER['REMOTE_ADDR'] ?? null));
        if ($this->throttle->isBlocked('CUSTOMER', $accountBucket, $ipBucket)) {
            PasswordPolicy::verify(strlen($password) <= PasswordPolicy::MAX_BYTES ? $password : '', PasswordPolicy::DUMMY_HASH);
            $this->throttle->blockedAttempt('CUSTOMER', $accountBucket, $ipBucket);
            return ['errors' => ['authentication' => self::GENERIC_LOGIN_ERROR]];
        }
        // Existing passwords can predate signup policy; only bound input size here.
        $validInput = $identifier !== null && $password !== '' && strlen($password) <= PasswordPolicy::MAX_BYTES;
        $oldHash = (string) ($customer['password_hash'] ?? PasswordPolicy::DUMMY_HASH);
        $verified = PasswordPolicy::verify($validInput ? $password : '', $oldHash);
        if (!$validInput || !$verified || (string) ($customer['account_status'] ?? '') !== 'ACTIVE') {
            $this->throttle->failed('CUSTOMER', $accountBucket, $ipBucket);
            return ['errors' => ['authentication' => self::GENERIC_LOGIN_ERROR]];
        }

        $customerId = (int) $customer['id'];
        if (PasswordPolicy::needsRehash($oldHash)
            && !$this->customers->upgradePasswordHashIfCurrent($customerId, $oldHash, PasswordPolicy::hashVerified($password))) {
            return ['errors' => ['authentication' => self::GENERIC_LOGIN_ERROR]];
        }
        $this->throttle->succeeded('CUSTOMER', $accountBucket, $ipBucket);
        $this->customers->updateLastLogin($customerId);
        $this->session->signIn($customerId);

        return ['errors' => []];
    }

    /**
     * @return array{errors: array<string, string>, customer_id?: int}
     */
    public function register(array $input): array
    {
        [$clean, $errors] = $this->validateRegistration($input);

        if ($errors !== []) {
            return ['errors' => $errors];
        }

        if ($this->customers->findByEmail($clean['email']) !== null) {
            $errors['email'] = 'This email address is already used.';
        }
        if ($this->customers->findByPhoneNumber($clean['phone_number']) !== null) {
            $errors['phone_number'] = 'This primary phone number is already used.';
        }
        if ($clean['second_phone_number'] !== ''
            && $this->customers->findBySecondPhoneNumber($clean['second_phone_number']) !== null
        ) {
            $errors['second_phone_number'] = 'This secondary phone number is already used.';
        }
        if ($errors !== []) {
            return ['errors' => $errors];
        }

        // PASSWORD IDIOM: Store a slow one-way password hash, never the original password text.
        // password_hash() receives the complete password. It is never logged,
        // truncated, saved in form flash data, or placed in the session.
        $passwordHash = PasswordPolicy::hashNew($clean['password']);

        try {
            $customerId = $this->customers->createWithPrimaryAddress(
                [
                    'first_name' => $clean['first_name'],
                    'last_name' => $clean['last_name'],
                    'email' => $clean['email'],
                    'phone_number' => $clean['phone_number'],
                    'second_phone_number' => $clean['second_phone_number'] === ''
                        ? null
                        : $clean['second_phone_number'],
                    'password_hash' => $passwordHash,
                ],
                [
                    'address_label' => $clean['address_label'] === '' ? null : $clean['address_label'],
                    'street_address' => $clean['street_address'],
                    'governorate' => $clean['governorate'],
                    'delegation' => $clean['delegation'],
                    'postal_code' => $clean['postal_code'],
                ]
            );
        } catch (PDOException $exception) {
            // WHAT: safely translate a duplicate-key race into field errors.
            // WHY:  pre-checks improve feedback, but only the database unique
            //       index can settle two signups arriving at the same instant.
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }

            if ($this->customers->findByEmail($clean['email']) !== null) {
                $errors['email'] = 'This email address is already used.';
            }
            if ($this->customers->findByPhoneNumber($clean['phone_number']) !== null) {
                $errors['phone_number'] = 'This primary phone number is already used.';
            }
            if ($clean['second_phone_number'] !== ''
                && $this->customers->findBySecondPhoneNumber($clean['second_phone_number']) !== null
            ) {
                $errors['second_phone_number'] = 'This secondary phone number is already used.';
            }

            return ['errors' => $errors !== [] ? $errors : [
                'form' => 'We could not create your account with those details.',
            ]];
        }

        return ['errors' => [], 'customer_id' => $customerId];
    }


    public function normalizeLoginIdentifier(string $rawIdentifier): ?array
    {
        $identifier = trim($rawIdentifier);

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false && strlen($identifier) <= 255) {
            return ['type' => 'email', 'value' => strtolower($identifier)];
        }

        $phoneNumber = self::normalizeTunisianPhone($identifier);
        return $phoneNumber === null ? null : ['type' => 'phone', 'value' => $phoneNumber];
    }


    public static function normalizeTunisianPhone(string $rawPhoneNumber): ?string
    {
        return CustomerRegistrationValidator::normalizePhone($rawPhoneNumber);
    }

    private function validateRegistration(array $input): array
    {
        return (new CustomerRegistrationValidator($this->governorates))->validate($input);
    }
}
