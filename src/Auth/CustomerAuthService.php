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
    public const GENERIC_LOGIN_ERROR = 'Invalid email or phone number, or password.';
    public const SUSPENDED_LOGIN_ERROR = 'This account is currently unavailable. Please contact us if you need help.';

    public function __construct(
        private readonly CustomerAccountRepository $customers,
        private readonly CustomerSession $session,
        private readonly array $governorates
    ) {
    }

    /** @return array{errors: array<string, string>} */
    public function signIn(string $rawIdentifier, string $password): array
    {
        if (trim($rawIdentifier) === '') {
            return ['errors' => ['login_identifier' => 'Email or phone number is required.']];
        }

        $identifier = $this->normalizeLoginIdentifier($rawIdentifier);

        if ($identifier === null) {
            return ['errors' => [
                'login_identifier' => 'Enter a valid email address or Tunisian phone number.',
            ]];
        }

        // Do not apply the signup policy here: an already-created password
        // might follow an older policy and still needs to be verifiable.
        if ($password === '') {
            return ['errors' => ['password' => 'Password is required.']];
        }
        if (strlen($password) > 4096) {
            return ['errors' => ['password' => 'Password is too long.']];
        }

        $customer = $identifier['type'] === 'email'
            ? $this->customers->findByEmail($identifier['value'])
            : $this->customers->findByPhoneNumber($identifier['value']);

        // WHAT: use the same response for an unknown identity and bad password.
        // WHY:  callers must not be able to discover registered emails/phones.
        if ($customer === null || !password_verify($password, (string) $customer['password_hash'])) {
            return ['errors' => ['authentication' => self::GENERIC_LOGIN_ERROR]];
        }

        if ((string) $customer['account_status'] !== 'ACTIVE') {
            return ['errors' => ['authentication' => self::SUSPENDED_LOGIN_ERROR]];
        }

        $customerId = (int) $customer['id'];
        $this->customers->updateLastLogin($customerId);
        $this->session->signIn($customerId);

        return ['errors' => []];
    }

    /** @return array{errors: array<string, string>, customer_id?: int} */
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

        // password_hash() receives the complete password. It is never logged,
        // truncated, saved in form flash data, or placed in the session.
        $passwordHash = password_hash($clean['password'], PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            return ['errors' => ['form' => 'We could not create your account right now. Please try again.']];
        }

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

    /** @return array{type: 'email'|'phone', value: string}|null */
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

        return preg_match('/^[0-9]{8}$/', $compact) === 1 ? $compact : null;
    }

    /** @return array{0: array<string, string>, 1: array<string, string>} */
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

        $passwordLength = strlen($clean['password']);
        if ($clean['password'] === '') {
            $errors['password'] = 'Password is required.';
        } elseif ($passwordLength < 8 || preg_match('/[A-Za-z]/', $clean['password']) !== 1
            || preg_match('/[0-9]/', $clean['password']) !== 1) {
            $errors['password'] = 'Use at least 8 characters, including a letter and a number.';
        } elseif ($passwordLength > 4096) {
            $errors['password'] = 'Password is too long.';
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
        if (preg_match('/^[0-9]{4}$/', $clean['postal_code']) !== 1) {
            $errors['postal_code'] = 'Postal code must contain exactly 4 digits.';
        }

        return [$clean, $errors];
    }

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

    private static function normalizeText(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private static function isEmptyOptionalPhone(string $value): bool
    {
        // WHAT: treat the formatter's untouched country prefix as an empty field.
        // WHY:  the secondary phone is optional even though "+216" is visible by default.
        $compact = preg_replace('/[\s-]+/', '', trim($value));
        return $compact === '' || $compact === '+216' || $compact === '00216';
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }
}
