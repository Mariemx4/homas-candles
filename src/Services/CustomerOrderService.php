<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\TransactionManager;
use App\Repositories\CustomerAccountReadRepository;
use App\Repositories\CustomerOrderEditRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderProductionRepository;
use App\Repositories\PaymentRepository;
use DateTimeImmutable;
use DomainException;
use PDO;

/**
 * Lets a signed-in customer edit or cancel their own order before preparation.
 *
 * Every action locks the order first, then checks ownership, the edit window
 * (REQUESTED/CONFIRMED and not yet prepared) and that the customer reviewed the
 * current version and prices. All SQL lives in CustomerOrderEditRepository.
 */
final class CustomerOrderService
{
    private readonly CustomerOrderEditRepository $edits;
    private readonly CustomerAccountReadRepository $accounts;
    private readonly PaymentRepository $payments;

    public function __construct(
        PDO $db,
        private readonly TransactionManager $transactions,
        private readonly OrderRepository $orders,
        private readonly OrderProductionRepository $catalogue,
        private readonly AccessoryStockService $accessories,
        private readonly ScentAvailabilityService $scents,
        private readonly array $settings
    ) {
        $this->edits = new CustomerOrderEditRepository($db);
        $this->accounts = new CustomerAccountReadRepository($db);
        $this->payments = new PaymentRepository($db);
    }

    /** Whether a customer may still edit or cancel this order: requested or confirmed, and not in preparation. */
    public static function editable(array $order): bool
    {
        return in_array($order['order_status'], ['REQUESTED', 'CONFIRMED'], true) && empty($order['preparation_started']);
    }

    /** A fingerprint of the order, its items and its payments; an edit is refused when it changed since the customer loaded the page. */
    public static function version(array $order, array $items): string
    {
        $fields = ['id','order_status','delivery_method','required_date','customer_name_snapshot','customer_email_snapshot',
            'customer_phone_snapshot','street_address_snapshot','delegation_snapshot','governorate_snapshot','postal_code_snapshot',
            'subtotal','total_amount','required_deposit_amount','customer_notes','updated_at','amount_paid','pending_amount'];
        $state = [];
        foreach ($fields as $field) {
            $state[$field] = in_array($field, ['amount_paid','pending_amount'], true)
                ? Money::formatMillis(Money::parseMillis((string)($order[$field] ?? '0')) ?? 0) : (string) ($order[$field] ?? '');
        }
        foreach ($items as $item) {
            $state['items'][] = [(string)$item['id'], (string)$item['quantity'], (string)$item['unit_price_snapshot']];
        }
        return hash('sha256', serialize($state));
    }

    /**
     * Reprices the customer's proposed changes without saving them.
     *
     * @param string $version the version() the customer's page was built from
     * @return array{success: bool, errors: array<string, string>, quote?: array<string, mixed>, signature?: string} the signature must be sent back to update()
     */
    public function preview(int $customerId, int $orderId, array $input, string $version): array
    {
        return $this->perform(function () use ($customerId, $orderId, $input, $version): array {
            [$order, $items] = $this->lock($customerId, $orderId, $version);
            $quote = $this->quote($order, $items, $input);
            return ['success' => true, 'errors' => [], 'quote' => $quote, 'signature' => self::signature($quote)];
        });
    }

    /**
     * Saves the customer's changes after re-checking stock and prices.
     *
     * The signature from preview() must still match, so the customer always agrees
     * to the price that is saved. The original sale and each revision are kept.
     * A confirmed order whose deposit went up goes back to REQUESTED until the
     * extra deposit is verified.
     *
     * @return array{success: bool, errors: array<string, string>, quote?: array<string, mixed>}
     */
    public function update(int $customerId, int $orderId, array $input, string $version, string $signature): array
    {
        return $this->perform(function () use ($customerId, $orderId, $input, $version, $signature): array {
            [$order, $items] = $this->lock($customerId, $orderId, $version);
            $quote = $this->quote($order, $items, $input);
            if (!hash_equals(self::signature($quote), $signature)) {
                throw new DomainException('Prices changed. Review the revised order before saving.');
            }
            $lines = $quote['lines'];
            $stock = [];
            $scentLines = [];
            foreach ($lines as $line) {
                if ($line['product_id'] === null) {
                    continue;
                }
                $stock[$line['product_id']] = ($stock[$line['product_id']] ?? 0) + $line['quantity'];
                $scentLines[] = ['product_id' => (int)$line['product_id'], 'scent_id' => $line['scent_id'], 'quantity' => $line['quantity']];
            }
            $this->accessories->assertAvailable($stock, $orderId);
            $this->scents->assertAvailable($scentLines, $orderId);
            // Keep the original sale and every revision before permitting a price-snapshot change.
            $editId = $this->edits->recordEdit(
                $orderId,
                $customerId,
                (string) $order['total_amount'],
                Money::formatMillis($quote['total_millis']),
                json_encode(['before' => $order, 'items_before' => $items, 'after' => $quote], JSON_THROW_ON_ERROR)
            );
            // The snapshot-protection trigger only accepts price changes tagged with this edit.
            $this->edits->setActiveEdit($editId);
            foreach ($lines as $line) {
                $this->edits->updateItemPricing($orderId, $line['id'], [
                    'quantity' => $line['quantity'],
                    'unit' => Money::formatMillis($line['unit_millis']),
                    'total' => Money::formatMillis($line['total_millis']),
                    'base' => $line['base_price'],
                    'quantity_unit' => $line['quantity_price'],
                    'tier' => $line['quantity_tier'],
                ]);
            }
            $this->edits->updateOrder($orderId, $quote['values'] + [
                'subtotal' => Money::formatMillis($quote['subtotal_millis']),
                'fee' => Money::formatMillis($quote['delivery_millis']),
                'total' => Money::formatMillis($quote['total_millis']),
                'deposit' => Money::formatMillis($quote['deposit_millis']),
                'promo' => Money::formatMillis($quote['promo_millis']),
                'manual' => Money::formatMillis($quote['manual_millis']),
                'savings' => Money::formatMillis($quote['savings_millis']),
            ]);
            $this->edits->setActiveEdit('');
            // An increased deposit needs verification before the order can stay confirmed.
            if ($order['order_status'] === 'CONFIRMED' && $quote['deposit_due_millis'] > 0) {
                $this->status($orderId, 'CONFIRMED', 'REQUESTED', 'Customer edit requires additional verified deposit before confirmation.');
            }
            return ['success' => true,'errors' => [],'quote' => $quote];
        });
    }

    /**
     * Cancels the customer's order before preparation; recorded payments then need an administrator's refund review.
     *
     * @return array{success: bool, errors: array<string, string>}
     */
    public function cancel(int $customerId, int $orderId, string $version): array
    {
        return $this->perform(function () use ($customerId, $orderId, $version): array {
            [$order] = $this->lock($customerId, $orderId, $version);
            $this->status($orderId, $order['order_status'], 'CANCELLED', 'Cancelled by customer before preparation. Recorded payments require administrator review for refund.');
            return ['success' => true,'errors' => []];
        });
    }

    private function lock(int $customerId, int $orderId, string $version): array
    {
        $order = $this->orders->lockForEdit($orderId);
        if ($order === null || (int)$order['customer_id'] !== $customerId) {
            throw new DomainException('Order not found.');
        }
        if ($this->accounts->activeAccount($customerId) === null) {
            throw new DomainException('Your active account could not be found.');
        }
        if (!self::editable($order)) {
            throw new DomainException('Orders can only be edited or cancelled while requested or confirmed.');
        }
        // Checked in the history too: an order sent back to CONFIRMED stays locked.
        if ($this->edits->preparationHasStarted($orderId)) {
            throw new DomainException('Preparation has already started. This order can no longer be edited or cancelled.');
        }
        $sums = $this->payments->lockSumsForOrder($orderId);
        $order['amount_paid'] = $sums['verified'];
        $order['pending_amount'] = $sums['pending'];
        $items = $this->accounts->orderItems($customerId, $orderId);
        if (!hash_equals(self::version($order, $items), $version)) {
            throw new DomainException('This order or its payments changed. Reload the order and review it again.');
        }
        return [$order, $items];
    }

    private function quote(array $order, array $items, array $input): array
    {
        $text = static fn ($key) => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        $method = $text('delivery_method');
        if (!in_array($method, ['DELIVERY','PICKUP'], true)) {
            throw new DomainException('Choose delivery or pickup.');
        }
        $values = ['delivery_method' => $method,'required_date' => $text('required_date'),'customer_notes' => $text('customer_notes') ?: null];
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $values['required_date'], new \DateTimeZone(BusinessClock::ZONE));
        $today = \App\Services\BusinessClock::today();
        if (!$date || $date->format('Y-m-d') !== $values['required_date']) {
            throw new DomainException('Choose a valid required date.');
        }
        if ($values['required_date'] !== $order['required_date'] && ($date < $today->modify('+' . $this->settings['preparation_days'] . ' days') || $date > $today->modify('+' . $this->settings['maximum_booking_days'] . ' days'))) {
            throw new DomainException('Choose a required date within the preparation and booking window.');
        }
        if (mb_strlen($values['customer_notes'] ?? '') > $this->settings['customer_notes_max_length']) {
            throw new DomainException('Keep your notes to 500 characters.');
        }
        foreach (['street_address' => 255,'delegation' => 100,'governorate' => 100,'postal_code' => 4] as $key => $max) {
            $value = $text($key);
            if ($method === 'DELIVERY' && ($value === '' || mb_strlen($value) > $max)) {
                throw new DomainException('Complete the delivery address within its field limits.');
            }
            $values[$key] = $method === 'DELIVERY' ? $value : null;
        }
        if ($method === 'DELIVERY' && !preg_match('/^[0-9]{4}$/D', $values['postal_code'])) {
            throw new DomainException('Enter a four-digit postal code.');
        }
        $quantities = $input['quantities'] ?? null;
        if (!is_array($quantities) || count($quantities) !== count($items)) {
            throw new DomainException('Review the quantities of every order item.');
        }
        $productIds = array_values(array_unique(array_filter(array_column($items, 'product_id'))));
        $this->edits->lockProducts($productIds);
        $products = $this->catalogue->findOrderableProducts($productIds);
        $subtotal = 0;
        $savings = 0;
        $lines = [];
        foreach ($items as $item) {
            $raw = $quantities[$item['id']] ?? null;
            $quantity = is_string($raw) ? filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1,'max_range' => 1000000]]) : false;
            if ($quantity === false) {
                throw new DomainException('Every item needs a quantity between 1 and 1,000,000.');
            }
            $unit = Money::parseMillis((string)$item['unit_price_snapshot']);
            $tier = $item['quantity_tier_snapshot'];
            $base = Money::parseMillis((string)($item['catalogue_unit_price_snapshot'] ?? $item['unit_price_snapshot']));
            // Reprice changed catalogue quantities; custom/manual agreed prices stay per unit.
            $basePrice = $item['catalogue_unit_price_snapshot'];
            $quantityPrice = $item['quantity_unit_price_snapshot'];
            if ($quantity !== (int)$item['quantity'] && ($order['order_source'] === 'CART' || $item['recipe_source'] === 'CATALOGUE') && $item['product_id'] !== null) {
                $product = $products[$item['product_id']] ?? null;
                if ($product === null) {
                    throw new DomainException('This product is no longer available for quantity changes.');
                }
                $audit = OrderPricingService::unitPrice($product, $quantity);
                $unit = $audit['quantity_millis'];
                $base = $audit['base_millis'];
                $tier = $audit['tier'];
                $basePrice = Money::formatMillis($base);
                $quantityPrice = Money::formatMillis($unit);
            } elseif ($quantity !== (int)$item['quantity'] && isset($products[$item['product_id'] ?? 0]) && $quantity < $products[$item['product_id']]['minimum_quantity']) {
                throw new DomainException('The quantity is below this product\'s minimum.');
            }
            $lineTotal = $unit * $quantity;
            $subtotal += $lineTotal;
            $savings += max(0, ($base - $unit) * $quantity);
            $lines[] = ['id' => (int)$item['id'],'product_id' => $item['product_id'] === null ? null : (int)$item['product_id'],
                'scent_id' => $item['selected_scent_id'] === null ? null : (int)$item['selected_scent_id'],'name' => $item['product_name_snapshot'],
                'quantity' => $quantity,'unit_millis' => $unit,'total_millis' => $lineTotal,'quantity_tier' => $tier,'base_price' => $basePrice,'quantity_price' => $quantityPrice];
        }
        if ($subtotal < $this->settings['minimum_order_millis']) {
            throw new DomainException('The minimum order is ' . Money::formatMillis($this->settings['minimum_order_millis']) . ' DT of pieces, excluding delivery.');
        }
        $fee = $method === 'PICKUP' ? 0 : max($this->settings['delivery_fee_millis'], $order['delivery_method'] === 'DELIVERY' ? Money::parseMillis((string)$order['delivery_fee']) : 0);
        $discountInput = ['promo_code' => $order['promo_code_snapshot'] ?? '', 'manual_discount_type' => $order['manual_discount_type'] ?? '',
            'manual_discount_value' => (string)($order['manual_discount_value'] ?? ''), 'manual_discount_reason' => $order['manual_discount_reason'] ?? ''];
        $promo = empty($order['promo_code_snapshot']) ? null : ['is_active' => 1,'valid_from' => null,'valid_until' => null,'minimum_subtotal' => '0.000',
            'discount_type' => $order['promo_type_snapshot'],'discount_value' => $order['promo_value_snapshot']];
        $quote = OrderPricingService::calculate($subtotal, $fee, $discountInput, $promo);
        $quote['deposit_millis'] = OrderPricingService::deposit($quote['total_millis'], $this->settings['deposit_threshold_millis'], $this->settings['deposit_percent']);
        $paid = Money::parseMillis((string)$order['amount_paid']);
        $pending = Money::parseMillis((string)$order['pending_amount']);
        return $quote + ['values' => $values,'lines' => $lines,'savings_millis' => $savings,'paid_millis' => $paid,'pending_millis' => $pending,
            'remaining_millis' => max(0, $quote['total_millis'] - $paid),'credit_millis' => max(0, $paid - $quote['total_millis']),
            'deposit_due_millis' => max(0, $quote['deposit_millis'] - $paid)];
    }

    private static function signature(array $quote): string
    {
        return hash('sha256', serialize($quote));
    }

    private function status(int $orderId, string $old, string $new, string $note): void
    {
        $this->edits->changeStatus($orderId, $old, $new, $note);
    }

    private function perform(callable $action): array
    {
        try {
            return $this->transactions->run($action);
        } catch (DomainException $e) {
            return ['success' => false,'errors' => ['form' => $e->getMessage()]];
        }
    }
}
