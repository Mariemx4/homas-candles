<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\FormInput;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Checks the "record a payment" form (administrator) and the D17 proof form (customer).
 *
 * validate() returns [values, errors]. It checks only the form itself; limits
 * that depend on the order (amount due, duplicates) are checked by
 * PaymentService while the order is locked.
 */
final class PaymentRecordValidator
{
    /**
     * @return array{0: array{order_id: int, payment_method: string, payment_type: string, amount_millis: int,
     *                        d17_reference: ?string, customer_declared_paid_at: ?string, request_key: ?string},
     *               1: array<string, string>}
     */
    public static function validate(array $input, DateTimeImmutable $now): array
    {
        $errors = [];
        $string = static fn (string $key): string => FormInput::trimmed($input, $key);
        // Identifies one form submission so a double click cannot record it twice.
        $requestKey = $string('request_key');
        if ($requestKey !== '' && preg_match('/^[a-f0-9]{64}$/', $requestKey) !== 1) {
            $errors['form'] = 'The payment submission is invalid. Reload the form.';
        }

        $orderId = filter_var($string('order_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($orderId === false) {
            $errors['order_id'] = 'The order identifier is invalid.';
            $orderId = 0;
        }

        $method = strtoupper($string('payment_method'));
        if (!in_array($method, PaymentService::METHODS, true)) {
            $errors['payment_method'] = 'Select a supported payment method.';
        }

        $type = strtoupper($string('payment_type'));
        if (!in_array($type, PaymentService::TYPES, true)) {
            $errors['payment_type'] = 'Select a valid payment type.';
        }

        $amountMillis = Money::parseMillis($string('amount'));
        if ($amountMillis === null || $amountMillis <= 0) {
            $errors['amount'] = 'Enter an amount greater than zero, with up to three decimals.';
            $amountMillis = 0;
        }

        $reference = $string('d17_reference');
        if (Text::length($reference) > 100) {
            $errors['d17_reference'] = 'The D17 reference must be 100 characters or fewer.';
        }
        if ($method !== 'D17' && $reference !== '') {
            $errors['d17_reference'] = 'A D17 reference can only be recorded for D17 payments.';
        }

        // The customer's own payment time is entered in Tunisia time and stored in UTC.
        $declaredAtRaw = $string('customer_declared_paid_at');
        $declaredAt = null;
        if ($declaredAtRaw !== '') {
            if ($method !== 'D17') {
                $errors['customer_declared_paid_at'] = 'A customer-declared date only applies to D17 payments.';
            } else {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $declaredAtRaw, new DateTimeZone(BusinessClock::ZONE));
                if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d\TH:i') !== $declaredAtRaw || $date > $now) {
                    $errors['customer_declared_paid_at'] = 'Enter a valid date and time that is not in the future.';
                } else {
                    $declaredAt = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                }
            }
        }

        return [[
            'order_id' => (int) $orderId,
            'payment_method' => $method,
            'payment_type' => $type,
            'amount_millis' => $amountMillis,
            'd17_reference' => $reference === '' ? null : $reference,
            'customer_declared_paid_at' => $declaredAt,
            'request_key' => $requestKey === '' ? null : $requestKey,
        ], $errors];
    }
}
