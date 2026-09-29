<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Business service for Checkout; it turns the session cart into one placed order.
 *
 * DATA FLOW: public/checkout.php supplies untrusted form values. This service
 * re-reads the cart from the database through CartService, validates contact,
 * address and date details, calculates authoritative totals and the deposit,
 * then asks OrderRepository to save the order, its frozen item snapshots, and
 * its first status-history event in one transaction.
 */

namespace App\Services;

use App\Auth\CustomerAuthService;
use App\Database\TransactionManager;
use App\Repositories\OrderRepository;
use Closure;
use DateTimeImmutable;
use DomainException;

final class CheckoutService
{
    public const DELIVERY_METHODS = ['DELIVERY', 'PICKUP'];

    // WHAT: the largest amount a DECIMAL(12,3) column can hold, in millis.
    // WHY:  rejecting it here gives the customer a clear message instead of
    //       a database error halfway through the transaction.
    private const MAX_MONEY_MILLIS = 999999999999;

    // A Closure gives the production code a secure generator/clock while
    // allowing deterministic values to be supplied by a focused test.
    private readonly Closure $orderNumberGenerator;
    private readonly Closure $today;

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     *
     * @param array<string, int> $settings Values from app/config/checkout.php.
     * @param list<string> $governorates Values from app/config/governorates.php.
     */
    public function __construct(
        private readonly CartService $cart,
        private readonly OrderRepository $orders,
        private readonly TransactionManager $transactions,
        private readonly array $settings,
        private readonly array $governorates,
        ?callable $orderNumberGenerator = null,
        ?callable $today = null,
        // Null only in the SQLite checkout harness, which cannot lock rows;
        // app/Bootstrap/checkout.php always supplies it.
        private readonly ?AccessoryStockService $accessoryStock = null,
        private readonly ?ScentAvailabilityService $scentAvailability = null
    ) {
        $this->orderNumberGenerator = $orderNumberGenerator === null
            ? static fn (): string => 'ORD-C-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)))
            : Closure::fromCallable($orderNumberGenerator);
        $this->today = $today === null
            ? static fn (): DateTimeImmutable => new DateTimeImmutable('today')
            : Closure::fromCallable($today);
    }

    /**
     * FUNCTION PURPOSE: Build the priced summary the customer reviews before placing an order.
     *
     * WHAT: every number here is recalculated from the database-backed cart.
     * WHY:  the browser never supplies a price; it only chooses a delivery
     *       method, and the fee for that method comes from configuration.
     *
     * @return array<string, mixed>
     */
    public function summary(string $deliveryMethod = 'DELIVERY'): array
    {
        $items = $this->cart->items();
        $lines = [];
        $subtotalMillis = 0;
        foreach ($items as $item) {
            $unitMillis = $this->toMillis($item['price']);
            $lineMillis = $unitMillis * (int) $item['quantity'];
            $subtotalMillis += $lineMillis;
            $lines[] = $item + ['unit_price_millis' => $unitMillis, 'line_total_millis' => $lineMillis];
        }

        $method = in_array($deliveryMethod, self::DELIVERY_METHODS, true) ? $deliveryMethod : 'DELIVERY';
        $deliveryFeeMillis = $lines === [] || $method === 'PICKUP' ? 0 : $this->settings['delivery_fee_millis'];
        $totalMillis = $subtotalMillis + $deliveryFeeMillis;
        $minimumMillis = $this->settings['minimum_order_millis'];

        return [
            'items' => $lines,
            'item_count' => array_sum(array_column($lines, 'quantity')),
            'delivery_method' => $method,
            'subtotal_millis' => $subtotalMillis,
            'delivery_fee_millis' => $deliveryFeeMillis,
            'total_millis' => $totalMillis,
            'deposit_millis' => $this->depositFor($totalMillis),
            'minimum_millis' => $minimumMillis,
            'meets_minimum' => $lines !== [] && $subtotalMillis >= $minimumMillis,
            'shortfall_millis' => max(0, $minimumMillis - $subtotalMillis),
            'signature' => $this->signature($lines),
        ];
    }

    /**
     * FUNCTION PURPOSE: Return the date window and wording shown as preparation information.
     *
     * @return array{earliest: string, latest: string, preparation_days: int, deposit_threshold_millis: int, deposit_percent: int}
     */
    public function preparationInfo(): array
    {
        $today = ($this->today)();

        return [
            'earliest' => $today->modify('+' . $this->settings['preparation_days'] . ' days')->format('Y-m-d'),
            'latest' => $today->modify('+' . $this->settings['maximum_booking_days'] . ' days')->format('Y-m-d'),
            'preparation_days' => $this->settings['preparation_days'],
            'deposit_threshold_millis' => $this->settings['deposit_threshold_millis'],
            'deposit_percent' => $this->settings['deposit_percent'],
        ];
    }

    /**
     * FUNCTION PURPOSE: Pre-fill the checkout form from a signed-in customer's account.
     *
     * WHAT: returns the account name/contact details and primary address.
     * WHY:  saves typing only — the customer can still edit every value, and
     *       whatever they submit is what gets frozen on the order.
     *
     * @return array<string, string>
     */
    public function defaultsFor(?int $customerId): array
    {
        $defaults = [
            'customer_name' => '', 'customer_email' => '', 'customer_phone' => '',
            'delivery_method' => 'DELIVERY', 'street_address' => '', 'delegation' => '',
            'governorate' => '', 'postal_code' => '', 'required_date' => '', 'customer_notes' => '',
        ];
        $customer = $customerId === null ? null : $this->orders->findCustomer($customerId);
        if ($customer === null) {
            return $defaults;
        }
        foreach (['customer_name', 'customer_email', 'customer_phone', 'street_address', 'delegation', 'governorate', 'postal_code'] as $field) {
            $defaults[$field] = (string) ($customer[$field] ?? '');
        }

        return $defaults;
    }

    /**
     * FUNCTION PURPOSE: Validate the checkout form and atomically create the order.
     *
     * @return array{success: bool, errors: array<string, string>, values: array<string, string>, orderId?: int, orderNumber?: string}
     */
    public function place(array $input, ?int $customerId): array
    {
        [$values, $errors] = $this->validate($input);
        $summary = $this->summary($values['delivery_method']);

        // WHAT: cart-level rules come first and use the form-wide error key.
        // WHY:  if the cart is empty or too small, field errors are noise.
        if ($summary['items'] === []) {
            return $this->failure(['form' => 'Your cart is empty. Add a piece before checking out.'], $values);
        }
        if (!$summary['meets_minimum']) {
            return $this->failure(['form' => sprintf(
                'The minimum order is %s DT of pieces. Add %s DT more to continue.',
                self::formatMoney($summary['minimum_millis']),
                self::formatMoney($summary['shortfall_millis'])
            )], $values);
        }
        // WHAT: compare the cart the customer reviewed with the cart right now.
        // WHY:  a price edit by the administrator, a product being retired, or
        //       a second browser tab can change the cart after the page was
        //       rendered. The customer must never confirm one total and be
        //       charged another, so they are sent back to review it.
        $submittedSignature = is_string($input['cart_signature'] ?? null) ? $input['cart_signature'] : '';
        if (!hash_equals($summary['signature'], $submittedSignature)) {
            return $this->failure(['form' => 'Your cart or its prices changed while you were checking out. Please review the updated summary and confirm again.'], $values);
        }
        if ($summary['total_millis'] > self::MAX_MONEY_MILLIS) {
            return $this->failure(['form' => 'The order total exceeds the supported amount. Please contact us directly.'], $values);
        }
        if ($customerId !== null && $this->orders->findCustomer($customerId) === null) {
            $customerId = null;
        }
        if ($errors !== []) {
            return $this->failure($errors, $values);
        }

        // A transaction prevents half an order: if any item or history insert
        // fails, TransactionManager rolls back the order row as well.
        try {
            $result = $this->transactions->run(fn (): array => $this->insert($values, $summary, $customerId));
        } catch (DomainException $exception) {
            // Accessory stock was taken by another order after the cart was built.
            return $this->failure(['form' => $exception->getMessage() . ' Please adjust your cart.'], $values);
        }

        // Only empty the cart after the transaction committed; a failure above
        // throws before this line and leaves the customer's selection intact.
        $this->cart->clear();

        return ['success' => true, 'errors' => [], 'values' => $values] + $result;
    }

    /**
     * FUNCTION PURPOSE: Save the validated order; runs inside place()'s transaction.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $summary
     * @return array{orderId: int, orderNumber: string}
     */
    private function insert(array $values, array $summary, ?int $customerId): array
    {
        // WHAT: accessories in the cart — on their own or inside a set — must
        //       still be available (shelf stock − other open orders).
        // WHY:  the cart only compared against shelf stock when items were
        //       added; this check runs with the product rows locked, so two
        //       customers cannot both buy the last unit.
        if ($this->accessoryStock !== null) {
            $lines = [];
            foreach ($summary['items'] as $item) {
                $lines[(int) $item['product_id']] = ($lines[(int) $item['product_id']] ?? 0) + (int) $item['quantity'];
            }
            $this->accessoryStock->assertAvailable($lines);
        }
        // WHAT: each chosen scent must still have enough fragrance material for
        //       every line using it (stock − other open orders), checked with
        //       the material rows locked.
        // WHY:  the product page showed availability when it was loaded; this
        //       is the authoritative revalidation when the order is accepted.
        if ($this->scentAvailability !== null) {
            $this->scentAvailability->assertAvailable(array_map(static fn (array $item): array => [
                'product_id' => (int) $item['product_id'],
                'scent_id' => $item['scent_id'] === null ? null : (int) $item['scent_id'],
                'quantity' => (int) $item['quantity'],
            ], $summary['items']));
        }

        $orderNumber = ($this->orderNumberGenerator)();
        if (!is_string($orderNumber) || preg_match('/^[A-Z0-9-]{8,30}$/', $orderNumber) !== 1) {
            throw new \RuntimeException('The order number generator returned an invalid value.');
        }
        $isDelivery = $values['delivery_method'] === 'DELIVERY';
        $orderId = $this->orders->insertCartOrder([
            'order_number' => $orderNumber,
            'customer_id' => $customerId,
            'delivery_method' => $values['delivery_method'],
            'required_date' => $values['required_date'],
            'customer_name' => $values['customer_name'],
            'customer_email' => $values['customer_email'] === '' ? null : $values['customer_email'],
            'customer_phone' => $values['customer_phone'],
            // PICKUP orders store no address — chk_orders_delivery_address
            // only demands one for DELIVERY, and a stale address would mislead.
            'street_address' => $isDelivery ? $values['street_address'] : null,
            'delegation' => $isDelivery ? $values['delegation'] : null,
            'governorate' => $isDelivery ? $values['governorate'] : null,
            'postal_code' => $isDelivery ? $values['postal_code'] : null,
            'subtotal' => self::formatMoney($summary['subtotal_millis']),
            'delivery_fee' => self::formatMoney($summary['delivery_fee_millis']),
            'total_amount' => self::formatMoney($summary['total_millis']),
            'required_deposit' => self::formatMoney($summary['deposit_millis']),
            'customer_notes' => $values['customer_notes'] === '' ? null : $values['customer_notes'],
        ]);

        // WHAT: freeze each line exactly as it was priced in the summary.
        // WHY:  these snapshots are the agreed record; migration 015 stops
        //       them from being rewritten later.
        foreach ($summary['items'] as $item) {
            $this->orders->insertCartOrderItem([
                'order_id' => $orderId,
                'product_id' => $item['product_id'],
                'product_name' => $item['name'],
                'color_id' => $item['color_id'],
                'color_name' => $item['color_id'] === null ? null : $item['color'],
                'scent_id' => $item['scent_id'],
                'scent_name' => $item['scent_id'] === null ? null : $item['scent'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => self::formatMoney($item['unit_price_millis']),
                'line_total' => self::formatMoney($item['line_total_millis']),
            ]);
        }
        $this->orders->insertCustomerOrderHistory($orderId);

        return ['orderId' => $orderId, 'orderNumber' => $orderNumber];
    }

    /**
     * FUNCTION PURPOSE: Format thousandths of a dinar as the three-decimal string used by DECIMAL(12,3).
     */
    public static function formatMoney(int $millis): string
    {
        return intdiv($millis, 1000) . '.' . str_pad((string) ($millis % 1000), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Return normalized values and all field errors in one pass.
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function validate(array $input): array
    {
        $errors = [];
        $string = static fn (string $key): string => isset($input[$key]) && is_string($input[$key]) ? trim($input[$key]) : '';

        $name = preg_replace('/\s+/u', ' ', $string('customer_name')) ?? '';
        if ($this->length($name) < 2 || $this->length($name) > 201) {
            $errors['customer_name'] = 'Enter your full name (2 to 201 characters).';
        }
        $email = $string('customer_email');
        if ($email !== '' && ($this->length($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['customer_email'] = 'Enter a valid email address or leave it blank.';
        }
        // Reuse the signup rule so "+216 12 345 678" and "12345678" are equal.
        $phone = CustomerAuthService::normalizeTunisianPhone($string('customer_phone'));
        if ($phone === null) {
            $errors['customer_phone'] = 'Enter an 8-digit Tunisian phone number, with or without +216.';
        }

        $method = strtoupper($string('delivery_method'));
        if (!in_array($method, self::DELIVERY_METHODS, true)) {
            $errors['delivery_method'] = 'Choose delivery or pickup.';
            $method = 'DELIVERY';
        }

        // WHAT: the address is validated only when it will actually be used.
        // WHY:  mirrors chk_orders_delivery_address — PICKUP needs no address.
        $address = [
            'street_address' => preg_replace('/\s+/u', ' ', $string('street_address')) ?? '',
            'delegation' => preg_replace('/\s+/u', ' ', $string('delegation')) ?? '',
            'governorate' => $string('governorate'),
            'postal_code' => $string('postal_code'),
        ];
        if ($method === 'DELIVERY') {
            if ($address['street_address'] === '' || $this->length($address['street_address']) > 255) {
                $errors['street_address'] = 'Enter your street address (up to 255 characters).';
            }
            if ($address['delegation'] === '' || $this->length($address['delegation']) > 100) {
                $errors['delegation'] = 'Enter your delegation (up to 100 characters).';
            }
            if (!in_array($address['governorate'], $this->governorates, true)) {
                $errors['governorate'] = 'Choose your governorate.';
            }
            if (preg_match('/^[0-9]{4}$/', $address['postal_code']) !== 1) {
                $errors['postal_code'] = 'Postal code must contain exactly 4 digits.';
            }
        }

        // WHAT: the required date must leave time to hand-make the pieces.
        // WHY:  the '!' format resets unspecified fields, and the round-trip
        //       comparison rejects overflow dates such as 2026-02-31.
        $requiredDate = $string('required_date');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $requiredDate);
        $window = $this->preparationInfo();
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $requiredDate) {
            $errors['required_date'] = 'Choose the date you need your order.';
        } elseif ($requiredDate < $window['earliest']) {
            $errors['required_date'] = sprintf(
                'Each piece is handmade to order and needs at least %d days. Choose %s or later.',
                $window['preparation_days'],
                $window['earliest']
            );
        } elseif ($requiredDate > $window['latest']) {
            $errors['required_date'] = 'Choose a date no later than ' . $window['latest'] . '.';
        }

        $notes = $string('customer_notes');
        if ($this->length($notes) > $this->settings['customer_notes_max_length']) {
            $errors['customer_notes'] = 'Notes must be ' . $this->settings['customer_notes_max_length'] . ' characters or fewer.';
        }

        // WHAT: an explicit acknowledgement of preparation time and deposit.
        // WHY:  the requirements say the customer must see the preparation
        //       information before confirming; the tick box proves they did.
        if (($input['accept_terms'] ?? null) !== '1') {
            $errors['accept_terms'] = 'Please confirm you have read the preparation and payment information.';
        }

        return [[
            'customer_name' => $name,
            'customer_email' => $email,
            'customer_phone' => $phone ?? $string('customer_phone'),
            'delivery_method' => $method,
            'street_address' => $address['street_address'],
            'delegation' => $address['delegation'],
            'governorate' => $address['governorate'],
            'postal_code' => $address['postal_code'],
            'required_date' => $requiredDate,
            'customer_notes' => $notes,
        ], $errors];
    }

    /**
     * FUNCTION PURPOSE: Calculate the minimum deposit for an order total.
     *
     * WHAT: 0 below the threshold, otherwise the configured percentage.
     * WHY:  "+ 50" before intdiv rounds half-up to the nearest millime, which
     *       matches ROUND(total_amount * 0.400, 3) in chk_orders_required_deposit.
     */
    private function depositFor(int $totalMillis): int
    {
        if ($totalMillis < $this->settings['deposit_threshold_millis']) {
            return 0;
        }

        return intdiv(($totalMillis * $this->settings['deposit_percent']) + 50, 100);
    }

    /**
     * FUNCTION PURPOSE: Fingerprint exactly what the customer is being asked to pay for.
     *
     * @param list<array<string, mixed>> $lines
     */
    private function signature(array $lines): string
    {
        $parts = array_map(
            static fn (array $line): string => $line['line_id'] . ':' . $line['quantity'] . ':' . $line['unit_price_millis'],
            $lines
        );

        return hash('sha256', implode('|', $parts) . '|fee:' . $this->settings['delivery_fee_millis']);
    }

    /**
     * FUNCTION PURPOSE: Convert a catalogue price to integer thousandths of a dinar.
     *
     * WHY: selling_price is DECIMAL(12,3); rounding after multiplying removes
     *      the tiny binary error a float such as 12.345 carries.
     */
    private function toMillis(float|int|string $price): int
    {
        return (int) round(((float) $price) * 1000);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $values
     * @return array{success: false, errors: array<string, string>, values: array<string, string>}
     */
    private function failure(array $errors, array $values): array
    {
        return ['success' => false, 'errors' => $errors, 'values' => $values];
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
