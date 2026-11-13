<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AdminLifecycleRepository;
use App\Database\TransactionManager;
use App\Auth\PasswordPolicy;
use App\Auth\CustomerAuthService;
use DomainException;
use PDOException;

/** Profile changes never rewrite historical order or request snapshots. */
final class AccountProfileService
{
    public function __construct(private readonly AdminLifecycleRepository $records, private readonly TransactionManager $transactions)
    {
    }

    public static function version(array $profile, array $addresses = []): string
    {
        $values = [];
        foreach (['id','first_name','last_name','email','phone_number','second_phone_number','second_email','account_status'] as $key) {
            $values[$key] = isset($profile[$key]) ? (string)$profile[$key] : null;
        }
        usort($addresses, static fn (array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);
        $values['addresses'] = array_map(static function (array $row): array {
            ksort($row);
            return array_map(static fn ($value) => $value === null ? null : (string)$value, $row);
        }, $addresses);
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    public function deactivateOwnAccount(int $customer): array
    {
        try {
            return $this->transactions->run(function () use ($customer): array {
                $row = $this->records->find('customers', $customer, true);
                if (!$row || $row['account_status'] !== 'ACTIVE') {
                    throw new DomainException('Your active account could not be found.');
                }
                $before = $this->records->accountSnapshot('customers', $customer);
                $this->records->accountStatus('customers', $customer, false);
                $this->records->recordAccountChange('customers', $customer, $customer, true, 'DEACTIVATED', $before, $this->records->accountSnapshot('customers', $customer));
                return ['success' => true, 'errors' => []];
            });
        } catch (DomainException $e) {
            return ['success' => false, 'errors' => ['form' => $e->getMessage()]];
        }
    }

    public function save(string $kind, ?int $id, array $input, int $actor, bool $customerSelf = false): array
    {
        try {
            return $this->transactions->run(function () use ($kind, $id, $input, $actor, $customerSelf): array {
                if (!in_array($kind, ['customers', 'administrators'], true)) {
                    throw new DomainException('Choose a valid account.');
                }
                if ($customerSelf) {
                    if ($kind !== 'customers' || $id !== $actor || ($this->records->find('customers', $actor, true)['account_status'] ?? '') !== 'ACTIVE') {
                        throw new DomainException('You can only edit your own active account.');
                    }
                } elseif (!$this->records->authorize($actor)) {
                    throw new DomainException('Active administrator access is required.');
                }
                $current = $id === null ? null : $this->records->find($kind, $id, true);
                if ($id !== null && !$current) {
                    throw new DomainException('The account could not be found.');
                }
                $before = $id === null ? null : $this->records->accountSnapshot($kind, $id);
                if ($current !== null && array_key_exists('profile_version', $input)) {
                    $addresses = $kind === 'customers' ? $this->records->addresses($id) : [];
                    if (!is_string($input['profile_version']) || !hash_equals(self::version($current, $addresses), $input['profile_version'])) {
                        throw new DomainException('This profile changed elsewhere. Your entries are retained. Review the latest profile before saving again.');
                    }
                }
                if ($id === null && $kind !== 'administrators') {
                    throw new DomainException('Use the existing customer registration or manual-order account flow.');
                }
                $text = static fn ($key) => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
                $values = [];
                $errors = [];
                foreach (['first_name', 'last_name'] as $key) {
                    $values[$key] = $text($key);
                    if ($values[$key] === '' || mb_strlen($values[$key]) > 100) {
                        $errors[$key] = 'Enter a name of up to 100 characters.';
                    }
                }
                $values['email'] = strtolower($text('email'));
                if (strlen($values['email']) > 255 || !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
                    $errors['email'] = 'Enter a valid primary email.';
                }
                $values['phone_number'] = CustomerAuthService::normalizeTunisianPhone($text('phone_number'));
                if ($values['phone_number'] === null) {
                    $errors['phone_number'] = 'Enter an eight-digit Tunisian phone number.';
                }
                $values['second_phone_number'] = $text('second_phone_number') === '' ? null : CustomerAuthService::normalizeTunisianPhone($text('second_phone_number'));
                if ($text('second_phone_number') !== '' && ($values['second_phone_number'] === null || $values['second_phone_number'] === $values['phone_number'])) {
                    $errors['second_phone_number'] = 'Enter a different valid secondary phone or leave it blank.';
                }
                // Preserve optional details when an older administrator form omits them.
                $values['second_email'] = array_key_exists('second_email', $input) ? (strtolower($text('second_email')) ?: null) : ($current['second_email'] ?? null);
                if ($values['second_email'] !== null && (!filter_var($values['second_email'], FILTER_VALIDATE_EMAIL) || strlen($values['second_email']) > 255 || strcasecmp($values['second_email'], $values['email']) === 0)) {
                    $errors['second_email'] = 'Enter a different valid secondary email or leave it blank.';
                }
                if ($id === null) {
                    $password = is_string($input['password'] ?? null) ? $input['password'] : '';
                    $error = PasswordPolicy::newPasswordError($password);
                    if ($error) {
                        $errors['password'] = $error;
                    } else {
                        $values['password_hash'] = PasswordPolicy::hashNew($password);
                        $values['account_status'] = 'ACTIVE';
                    }
                }
                $updates = [];
                $new = [];
                $deletes = [];
                $primaryAddress = null;
                if ($kind === 'customers') {
                    $owned = array_column($this->records->addresses((int) $id), null, 'id');
                    if (isset($input['addresses']) && !is_array($input['addresses'])) {
                        throw new DomainException('Enter valid addresses.');
                    }
                    foreach ($input['addresses'] ?? [] as $addressId => $address) {
                        if (!isset($owned[$addressId]) || !is_array($address)) {
                            throw new DomainException('Choose an address owned by this customer.');
                        }
                        if (($address['remove'] ?? '') === '1') {
                            $deletes[] = (int) $addressId;
                            continue;
                        }
                        $updates[$addressId] = $this->address($address, $errors);
                    }
                    if (isset($input['new_addresses']) && !is_array($input['new_addresses'])) {
                        throw new DomainException('Enter valid addresses.');
                    }
                    foreach ($input['new_addresses'] ?? [] as $address) {
                        if (!is_array($address)) {
                            throw new DomainException('Enter a valid address.');
                        }
                        if (!array_filter($address, static fn ($v) => is_string($v) && trim($v) !== '')) {
                            continue;
                        }
                        $new[] = $this->address($address, $errors);
                    }
                    $count = count($owned) - count($deletes) + count($new);
                    if ($count < 1 || $count > 3) {
                        $errors['address'] = 'Keep at least one address and no more than three.';
                    }
                    if (array_key_exists('primary_address_id', $input)) {
                        $candidate = is_string($input['primary_address_id'])
                            ? filter_var($input['primary_address_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                            : false;
                        if ($candidate === false || !isset($owned[$candidate]) || in_array($candidate, $deletes, true)) {
                            $errors['address'] = 'Choose a saved address you are keeping as your default.';
                        } else {
                            $primaryAddress = (int) $candidate;
                        }
                    }
                }
                if ($errors) {
                    return ['success' => false, 'errors' => $errors];
                }
                $saved = $this->records->saveProfile($kind, $id, $values);
                foreach ($deletes as $addressId) {
                    $this->records->deleteAddress($saved, $addressId);
                }
                foreach ($updates as $addressId => $address) {
                    $this->records->updateAddress($saved, (int) $addressId, $address);
                }
                foreach ($new as $address) {
                    $this->records->insertAddress($saved, $address);
                }
                if ($kind === 'customers') {
                    if ($primaryAddress !== null) {
                        $this->records->setPrimaryAddress($saved, $primaryAddress);
                    } else {
                        $this->records->ensurePrimaryAddress($saved);
                    }
                }
                $this->records->recordAccountChange($kind, $saved, $actor, $customerSelf, $id === null ? 'CREATED' : 'UPDATED', $before, $this->records->accountSnapshot($kind, $saved));
                return ['success' => true, 'errors' => [], 'id' => $saved];
            });
        } catch (DomainException $e) {
            return ['success' => false, 'errors' => ['form' => $e->getMessage()]];
        } catch (PDOException $e) {
            if (in_array($e->getCode(), ['23505', '23514'], true)) {
                return ['success' => false, 'errors' => ['form' => 'Those contact details are already used or do not meet the account rules.']];
            }
            throw $e;
        }
    }

    private function address(array $input, array &$errors): array
    {
        $values = [];
        foreach (['address_label' => 50, 'street_address' => 255, 'delegation' => 100, 'governorate' => 100, 'postal_code' => 4] as $key => $max) {
            $value = is_string($input[$key] ?? null) ? trim($input[$key]) : '';
            if (($key !== 'address_label' && $value === '') || mb_strlen($value) > $max) {
                $errors['address'] = 'Complete every address within its field limits.';
            }
            $values[$key] = $key === 'address_label' && $value === '' ? null : $value;
        }
        if (!preg_match('/^[0-9]{4}$/D', $values['postal_code'])) {
            $errors['address'] = 'Enter a four-digit postal code.';
        }
        return $values;
    }
}
