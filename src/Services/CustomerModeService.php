<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\PasswordPolicy;
use App\Repositories\CustomerAccountRepository;
use App\Repositories\OrderRepository;

/** Shared administrator account/link preparation for orders and enquiries. */
final class CustomerModeService
{
    public function __construct(private readonly OrderRepository $customers, private readonly ?CustomerAccountRepository $accounts)
    {
    }

    /**
     * Normalizes the customer part of a staff order or request form (existing account, new account or guest).
     *
     * For an existing account the contact details come from the account unless
     * the form explicitly supplied them.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>} [values, errors]
     */
    public function prepare(array $input): array
    {
        [$values, $errors] = OrderCustomerMode::normalize($input);
        if ($errors !== []) {
            return [$values, $errors];
        }
        if ($values['customer_mode'] === 'existing') {
            $id = filter_var($values['customer_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $profile = $id === false ? null : $this->customers->findCustomer($id);
            if ($profile === null) {
                return [$values, ['customer_id' => 'Search and explicitly select a valid customer account.']];
            }
            foreach (['customer_name','customer_email','customer_phone'] as $key) {
                if (!array_key_exists('existing_' . $key, $input)) {
                    $values[$key] = (string) ($profile[$key] ?? '');
                }
            }
        }
        if ($values['customer_mode'] === 'new') {
            $values['customer_email'] = strtolower(trim($values['customer_email']));
            $values['customer_name'] = trim($values['customer_name']) ?: substr(explode('@', $values['customer_email'])[0], 0, 100);
            [$first, $last] = array_pad(preg_split('/\s+/u', $values['customer_name'], 2) ?: [], 2, '');
            $values['account_first_name'] = $first;
            $values['account_last_name'] = $last;
            $length = static fn (string $text): int => function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
            if ($first === '' || $length($first) > 100 || $length($last) > 100) {
                $errors['customer_name'] = 'The first and remaining names must each be 100 characters or fewer.';
            }
            if (filter_var($values['customer_email'], FILTER_VALIDATE_EMAIL) === false || $length($values['customer_email']) > 255) {
                $errors['customer_email'] = 'Enter a valid email address for the new account.';
            }
            if (preg_match('/^[0-9]{8}$/', $values['customer_phone']) !== 1) {
                $errors['customer_phone'] = 'Enter an eight-digit phone number.';
            }
            $second = $values['customer_second_phone'];
            if ($second !== '' && (preg_match('/^[0-9]{8}$/', $second) !== 1 || $second === $values['customer_phone'])) {
                $errors['customer_second_phone'] = 'Enter a different eight-digit secondary phone number or leave it blank.';
            }
            $values['customer_second_phone'] = $second ?: null;
            $values['account_address'] = [];
            foreach (['street_address' => 255, 'delegation' => 100, 'governorate' => 100, 'postal_code' => 4] as $key => $maximum) {
                $text = is_string($values['account_' . $key] ?? null) ? trim($values['account_' . $key]) : '';
                $values['account_address'][$key] = $text;
                if ($text === '' || $length($text) > $maximum || ($key === 'postal_code' && preg_match('/^[0-9]{4}$/', $text) !== 1)) {
                    $errors['account_' . $key] = 'Enter a complete primary account address with a four-digit postal code.';
                }
            }
            if ($this->accounts === null) {
                $errors['customer_mode'] = 'Account creation is temporarily unavailable.';
            } elseif ($errors === []) {
                $errors += $this->conflicts($values);
            }
        }
        return [$values, $errors];
    }

    /**
     * Errors for a new account whose email or phone numbers already belong to an account.
     *
     * @return array<string, string>
     */
    public function conflicts(array $values): array
    {
        $errors = [];
        if ($values['customer_email'] !== null && $this->accounts->findByEmail($values['customer_email']) !== null) {
            $errors['customer_email'] = 'This email already belongs to an account. Search and link that account instead.';
        }
        if ($this->accounts->findByPhoneNumber($values['customer_phone']) !== null) {
            $errors['customer_phone'] = 'This phone already belongs to an account. Search and link that account instead.';
        }
        if ($values['customer_second_phone'] !== null && $this->accounts->findBySecondPhoneNumber($values['customer_second_phone']) !== null) {
            $errors['customer_second_phone'] = 'This secondary phone is already used by an account.';
        }
        return $errors;
    }

    /**
     * Creates a customer account with its first address on a customer's behalf.
     *
     * The password is random and unknown; the customer sets their own through
     * password reset.
     *
     * @return int the new customer ID
     */
    public function createAccount(array $values, ?int $administratorId = null): int
    {
        return $this->accounts->createWithPrimaryAddress(['first_name' => $values['account_first_name'], 'last_name' => $values['account_last_name'],
            'email' => $values['customer_email'], 'phone_number' => $values['customer_phone'], 'second_phone_number' => $values['customer_second_phone'],
            'password_hash' => PasswordPolicy::hashVerified(bin2hex(random_bytes(32)))], $values['account_address'], $administratorId);
    }
}
