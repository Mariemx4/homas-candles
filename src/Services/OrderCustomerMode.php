<?php

declare(strict_types=1);

namespace App\Services;

/** Maps an explicit form mode onto the existing account/link/snapshot workflow. */
final class OrderCustomerMode
{
    public const CREATE_MODES = ['existing', 'guest', 'new'];

    /** @return array{0: array, 1: array<string, string>} */
    public static function normalize(array $input, bool $allowNew = true): array
    {
        $mode = $input['customer_mode'] ?? null;
        if (!is_string($mode) || !in_array($mode, $allowNew ? self::CREATE_MODES : ['existing', 'guest'], true)) {
            return [$input, ['customer_mode' => 'Choose who this order is for.']];
        }
        $values = $input;
        foreach (['customer_name', 'customer_email', 'customer_phone'] as $field) {
            $value = $input[$mode . '_' . $field] ?? '';
            $values[$field] = is_string($value) ? $value : '';
        }
        if ($allowNew) {
            $values['customer_notes'] = is_string($input[$mode . '_customer_notes'] ?? null) ? $input[$mode . '_customer_notes'] : '';
        }
        $values['create_customer'] = $mode === 'new' ? '1' : '';
        $values['customer_second_phone'] = $mode === 'new' && is_string($input['new_customer_second_phone'] ?? null) ? $input['new_customer_second_phone'] : '';
        if ($mode !== 'existing') {
            $values['customer_id'] = '';
        }
        if ($mode === 'existing' && (!is_string($input['customer_id'] ?? null) || trim($input['customer_id']) === '')) {
            return [$values, ['customer_id' => 'Search and explicitly select a customer account.']];
        }
        foreach (['street_address', 'delegation', 'governorate', 'postal_code'] as $field) {
            if ($mode !== 'new') {
                unset($values['account_' . $field]);
            }
        }
        if ($mode === 'new') {
            $copy = $input['copy_account_address'] ?? '';
            if (!is_string($copy) || !in_array($copy, ['', '0', '1'], true)) {
                return [$values, ['copy_account_address' => 'Choose whether to use the account address for delivery.']];
            }
            if ($copy === '1') {
                foreach (['street_address', 'delegation', 'governorate', 'postal_code'] as $field) {
                    $values[$field] = is_string($input['account_' . $field] ?? null) ? $input['account_' . $field] : '';
                }
            }
        } else {
            unset($values['copy_account_address']);
        }
        return [$values, []];
    }
}
