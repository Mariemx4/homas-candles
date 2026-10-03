<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\TransactionManager;
use App\Repositories\OrderRepository;
use App\Repositories\OrderStatusRepository;
use DateTimeImmutable;

final class OrderEditService
{
    public const FIELDS = [
        'customer_id', 'customer_name', 'customer_email', 'customer_phone', 'delivery_method',
        'required_date', 'delivery_fee', 'street_address', 'delegation',
        'governorate', 'postal_code', 'customer_notes', 'administrator_notes',
    ];

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderStatusRepository $statuses,
        private readonly TransactionManager $transactions
    ) {
    }

    public static function values(array $order): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $column = in_array($field, ['customer_name', 'customer_email', 'customer_phone', 'street_address', 'delegation', 'governorate', 'postal_code'], true)
                ? $field . '_snapshot' : $field;
            $values[$field] = (string) ($order[$column] ?? '');
        }
        return $values;
    }

    public static function version(array $order): string
    {
        // Include values as well as the timestamp to detect edits within the same second.
        return hash('sha256', serialize([self::values($order), (string) $order['id'],
            $order['order_status'], $order['updated_at'], $order['price_locked_at'],
            $order['subtotal'], $order['total_amount'], $order['required_deposit_amount']]));
    }

    public static function canChangeFulfilment(array $order): bool
    {
        return true;
    }

    public function update(int $orderId, array $input, string $version, ?int $administratorId = null): array
    {
        if (array_key_exists('customer_mode', $input)) {
            [$input, $modeErrors] = OrderCustomerMode::normalize($input, false);
            if ($modeErrors !== []) { return $this->failure($modeErrors); }
        }
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = isset($input[$field]) && is_string($input[$field]) ? trim($input[$field]) : '';
        }
        return $this->transactions->run(function () use ($orderId, $values, $version, $administratorId): array {
            $order = $this->orders->lockForEdit($orderId);
            if ($order === null) {
                return $this->failure(['form' => 'The requested order could not be found.']);
            }
            if (!hash_equals(self::version($order), $version)) {
                return $this->failure(['form' => 'This order changed while you were editing. Reload the latest order before saving.']);
            }
            $errors = [];
            $customerId = $values['customer_id'] === '' ? null : filter_var($values['customer_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($customerId === false || ($customerId !== null && $this->orders->findCustomer($customerId) === null)) {
                $errors['customer_id'] = 'Select an existing customer account or choose Guest order.';
            }
            $length = static fn (string $value): int => function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
            if ($values['customer_name'] === '' || $length($values['customer_name']) > 201) {
                $errors['customer_name'] = 'Enter a customer name of no more than 201 characters.';
            }
            if (preg_match('/^[0-9]{8}$/', $values['customer_phone']) !== 1) {
                $errors['customer_phone'] = 'Enter an eight-digit phone number.';
            }
            if ($values['customer_email'] !== '' && ($length($values['customer_email']) > 255 || filter_var($values['customer_email'], FILTER_VALIDATE_EMAIL) === false)) {
                $errors['customer_email'] = 'Enter a valid email address or leave it blank.';
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $values['required_date']);
            if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $values['required_date']) {
                $errors['required_date'] = 'Select a valid required date.';
            }
            $method = $values['delivery_method'];
            if (!in_array($method, ['DELIVERY', 'PICKUP'], true)) {
                $errors['delivery_method'] = 'Select delivery or pickup.';
            }
            $fee = $method === 'PICKUP' ? 0 : Money::parseMillis($values['delivery_fee']);
            if ($fee === null || ($method === 'PICKUP' && $fee !== 0) || ($method === 'DELIVERY' && $fee < 9000)) {
                $errors['delivery_fee'] = 'Pickup requires a zero fee; delivery costs at least 9.000 TND.';
            }
            foreach (['street_address' => 255, 'delegation' => 100, 'governorate' => 100] as $field => $maximum) {
                if ($length($values[$field]) > $maximum || ($method === 'DELIVERY' && $values[$field] === '')) {
                    $errors[$field] = 'Enter a delivery address within the allowed field lengths.';
                }
            }
            if (($method === 'DELIVERY' || $values['postal_code'] !== '') && preg_match('/^[0-9]{4}$/', $values['postal_code']) !== 1) {
                $errors['postal_code'] = 'Enter a four-digit postal code.';
            }
            foreach (['customer_notes', 'administrator_notes'] as $field) {
                if ($length($values[$field]) > 10000) {
                    $errors[$field] = 'Notes must be 10,000 characters or fewer.';
                }
            }
            $total = OrderPricingService::total(Money::parseMillis((string) $order['subtotal']) ?? 0,
                Money::parseMillis((string) ($order['promo_discount_amount'] ?? '0')) ?? 0,
                Money::parseMillis((string) ($order['manual_discount_amount'] ?? '0')) ?? 0, $fee ?? 0);
            if ($total > 999999999999) {
                $errors['delivery_fee'] = 'The order total exceeds the supported monetary limit.';
            }
            // Payments remain factual records. A lower delivery fee may leave a credit
            // balance, which the detail page shows explicitly instead of blocking a switch.
            if ($errors !== []) {
                return $this->failure($errors);
            }
            $values['delivery_fee'] = Money::formatMillis($fee);
            $values['customer_id'] = $customerId;
            $values['total_amount'] = Money::formatMillis($total);
            $values['required_deposit_amount'] = $fee !== Money::parseMillis((string) $order['delivery_fee'])
                ? Money::formatMillis(OrderPricingService::deposit($total))
                : (string) $order['required_deposit_amount'];
            foreach (['customer_email', 'customer_notes', 'administrator_notes'] as $field) {
                $values[$field] = $values[$field] === '' ? null : $values[$field];
            }
            foreach (['street_address', 'delegation', 'governorate', 'postal_code'] as $field) {
                $values[$field] = $method === 'PICKUP' || $values[$field] === '' ? null : $values[$field];
            }
            $this->orders->updateDetails($orderId, $values);
            if ($method !== $order['delivery_method']) {
                $mappedStatus = $method === 'PICKUP'
                    ? (['IN_DELIVERY' => 'WAITING_FOR_PICKUP', 'DELIVERED' => 'PICKED_UP'][$order['order_status']] ?? $order['order_status'])
                    : (['WAITING_FOR_PICKUP' => 'IN_DELIVERY', 'PICKED_UP' => 'DELIVERED'][$order['order_status']] ?? $order['order_status']);
                if ($mappedStatus !== $order['order_status']) {
                    $this->statuses->updateStatus($orderId, $order['order_status'], $mappedStatus);
                    $this->statuses->addHistory($orderId, $order['order_status'], $mappedStatus, $administratorId,
                        'Status adjusted after fulfilment changed to ' . strtolower($method) . '.');
                }
            }
            return ['success' => true, 'errors' => []];
        });
    }

    private function failure(array $errors): array
    {
        return ['success' => false, 'errors' => $errors];
    }

}
