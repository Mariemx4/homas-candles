<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Authentication/session component for Customer Auth Service.
 *
 * DATA FLOW: Authentication entry points and guards use this layer to validate identities, manage session state, and pass authenticated account data into protected routes.
 */

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

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
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
        $accountBucket = $this->throttle->accountBucket($customer === null ? null : (int) $customer['id'],
            $identifier['value'] ?? $rawIdentifier);
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

    /**
     * FUNCTION PURPOSE: Validate and convert login identifier into a consistent internal shape.
     *
     * @return array{type: 'email'|'phone', value: string}|null
     */
    public function normalizeLoginIdentifier(string $rawIdentifier): ?array
    {
        $identifier = trim($rawIdentifier);

        // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false && strlen($identifier) <= 255) {
            return ['type' => 'email', 'value' => strtolower($identifier)];
        }

        $phoneNumber = self::normalizeTunisianPhone($identifier);
        return $phoneNumber === null ? null : ['type' => 'phone', 'value' => $phoneNumber];
    }

    /**
     * FUNCTION PURPOSE: Validate and convert tunisian phone into a consistent internal shape.
     */
    public static function normalizeTunisianPhone(string $rawPhoneNumber): ?string
    {
        $phoneNumber = trim($rawPhoneNumber);

        // Only digits, whitespace, hyphens and one optional leading + are
        // accepted. Removing every non-digit would accidentally accept letters.
        if ($phoneNumber === '' || preg_match('/^(?:\+)?[0-9\s-]+$/', $phoneNumber) !== 1) {
            return null;
        }

        $compact = preg_replace('/[\s-]+/', '', $phoneNumber);
        if (!is_string($compact)) {
            return null;
        }

        if (str_starts_with($compact, '+216')) {
            $compact = substr($compact, 4);
        } elseif (str_starts_with($compact, '00216')) {
            $compact = substr($compact, 5);
        }

        // VALIDATION IDIOM: Test the complete string against a regular-expression format before accepting it.
        return preg_match('/^[0-9]{8}$/', $compact) === 1 ? $compact : null;
    }

    /**
     * FUNCTION PURPOSE: Check registration against the application rules and return usable values or errors.
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function validateRegistration(array $input): array
    {
        $value = static fn (string $key): string => isset($input[$key]) && is_string($input[$key])
            ? $input[$key]
            : '';
        $clean = [
            'first_name' => self::normalizeText($value('first_name')),
            'last_name' => self::normalizeText($value('last_name')),
            'email' => strtolower(trim($value('email'))),
            'phone_number' => self::normalizeTunisianPhone($value('phone_number')) ?? '',
            // An empty secondary number (including the UI's default "+216"
            // prefix by itself) stays empty; only real local digits are normalized.
            'second_phone_number' => self::isEmptyOptionalPhone($value('second_phone_number'))
                ? ''
                : (self::normalizeTunisianPhone($value('second_phone_number')) ?? ''),
            'password' => $value('password'),
            'password_confirmation' => $value('password_confirmation'),
            'address_label' => self::normalizeText($value('address_label')),
            'street_address' => self::normalizeText($value('street_address')),
            'governorate' => trim($value('governorate')),
            'delegation' => self::normalizeText($value('delegation')),
            'postal_code' => trim($value('postal_code')),
        ];
        $errors = [];

        $this->validateRequiredText($clean['first_name'], 'first_name', 'First name', 100, $errors);
        $this->validateRequiredText($clean['last_name'], 'last_name', 'Last name', 100, $errors);

        if ($clean['email'] === '') {
            $errors['email'] = 'Email address is required.';
        } elseif (strlen($clean['email']) > 255) {
            $errors['email'] = 'Email address must contain no more than 255 characters.';
        // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
        } elseif (filter_var($clean['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address, such as name@example.com.';
        }

        if ($clean['phone_number'] === '') {
            $errors['phone_number'] = 'Enter an 8-digit Tunisian phone number, with or without +216.';
        }

        $hasSecondPhone = !self::isEmptyOptionalPhone($value('second_phone_number'));
        if ($hasSecondPhone && $clean['second_phone_number'] === '') {
            $errors['second_phone_number'] = 'Enter an 8-digit Tunisian phone number, with or without +216.';
        } elseif ($clean['second_phone_number'] !== ''
            && $clean['second_phone_number'] === $clean['phone_number']
        ) {
            $errors['second_phone_number'] = 'Secondary phone number must be different from the primary phone number.';
        }

        $passwordError = PasswordPolicy::newPasswordError($clean['password']);
        if ($passwordError !== null) {
            $errors['password'] = $passwordError;
        }
        if ($clean['password_confirmation'] === '') {
            $errors['password_confirmation'] = 'Password confirmation is required.';
        } elseif (!hash_equals($clean['password'], $clean['password_confirmation'])) {
            $errors['password_confirmation'] = 'Passwords do not match.';
        }

        if (self::length($clean['address_label']) > 50) {
            $errors['address_label'] = 'Address label must contain no more than 50 characters.';
        }
        $this->validateRequiredText($clean['street_address'], 'street_address', 'Street address', 255, $errors);
        if (!in_array($clean['governorate'], $this->governorates, true)) {
            $errors['governorate'] = 'Choose a governorate from the list.';
        }
        $this->validateRequiredText($clean['delegation'], 'delegation', 'Delegation', 100, $errors);
        // VALIDATION IDIOM: Test the complete string against a regular-expression format before accepting it.
        if (preg_match('/^[0-9]{4}$/', $clean['postal_code']) !== 1) {
            $errors['postal_code'] = 'Postal code must contain exactly 4 digits.';
        }

        return [$clean, $errors];
    }

    /**
     * FUNCTION PURPOSE: Check required text against the application rules and return usable values or errors.
     */
    private function validateRequiredText(
        string $value,
        string $key,
        string $label,
        int $maximum,
        array &$errors
    ): void {
        if ($value === '') {
            $errors[$key] = $label . ' is required.';
        } elseif (self::length($value) > $maximum) {
            $errors[$key] = $label . " must contain no more than {$maximum} characters.";
        }
    }

    /**
     * FUNCTION PURPOSE: Validate and convert text into a consistent internal shape.
     */
    private static function normalizeText(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * FUNCTION PURPOSE: Answer whether empty optional phone satisfies this condition.
     */
    private static function isEmptyOptionalPhone(string $value): bool
    {
        // WHAT: treat the formatter's untouched country prefix as an empty field.
        // WHY:  the secondary phone is optional even though "+216" is visible by default.
        $compact = preg_replace('/[\s-]+/', '', trim($value));
        return $compact === '' || $compact === '+216' || $compact === '00216';
    }

    /**
     * FUNCTION PURPOSE: Count characters in the supplied value safely for UTF-8 validation.
     */
    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }
}
