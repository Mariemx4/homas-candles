<?php

declare(strict_types=1);

namespace App\Auth;

/** Shared registration rules: Google omits only the local password requirement. */
final class CustomerRegistrationValidator
{
    public function __construct(private readonly array $governorates)
    {
    }

    public static function normalizePhone(string $rawPhoneNumber): ?string
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


    public function validate(array $input, bool $requirePassword = true): array
    {
        $value = static fn (string $key): string => isset($input[$key]) && is_string($input[$key])
            ? $input[$key]
            : '';
        $clean = [
            'first_name' => self::normalizeText($value('first_name')),
            'last_name' => self::normalizeText($value('last_name')),
            'email' => strtolower(trim($value('email'))),
            'phone_number' => self::normalizePhone($value('phone_number')) ?? '',
            // An empty secondary number (including the UI's default "+216"
            // prefix by itself) stays empty; only real local digits are normalized.
            'second_phone_number' => self::isEmptyOptionalPhone($value('second_phone_number'))
                ? ''
                : (self::normalizePhone($value('second_phone_number')) ?? ''),
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

        if ($requirePassword) {
            $passwordError = PasswordPolicy::newPasswordError($clean['password']);
            if ($passwordError !== null) {
                $errors['password'] = $passwordError;
            }
            if ($clean['password_confirmation'] === '') {
                $errors['password_confirmation'] = 'Password confirmation is required.';
            } elseif (!hash_equals($clean['password'], $clean['password_confirmation'])) {
                $errors['password_confirmation'] = 'Passwords do not match.';
            }
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
