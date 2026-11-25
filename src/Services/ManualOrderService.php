<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\TransactionManager;
use App\Repositories\CustomerAccountRepository;
use App\Repositories\OrderProductionRepository;
use App\Repositories\OrderRepository;
use Closure;
use DomainException;
use PDOException;

final class ManualOrderService
{
    // A Closure gives the production code a secure generator while allowing a
    // deterministic generator to be supplied by a focused automated test.
    private readonly Closure $orderNumberGenerator;
    private readonly CustomerModeService $customerModes;
    private readonly ManualOrderValidator $validator;

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly TransactionManager $transactions,
        private readonly OrderProductionRepository $catalogue,
        private readonly OrderProductionService $production,
        private readonly AccessoryStockService $accessories,
        private readonly ScentAvailabilityService $scents,
        ?callable $orderNumberGenerator = null,
        private readonly ?CustomerAccountRepository $customers = null,
        ?CustomerModeService $customerModes = null
    ) {
        // Existing/new/guest customer handling; built here when the caller does not supply it.
        $this->customerModes = $customerModes ?? new CustomerModeService($orders, $customers);
        $this->validator = new ManualOrderValidator($catalogue, $production);
        $this->orderNumberGenerator = $orderNumberGenerator === null
            ? static fn (): string => 'ORD-M-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)))
            : Closure::fromCallable($orderNumberGenerator);
    }

    /**
     * Create one manual order after validating every field.
     *
     * @return array{success: bool, errors: array<string, string>, orderId?: int, orderNumber?: string}
     */
    public function create(int $administratorId, array $input): array
    {
        try {
            return $this->transactions->run(fn (): array => $this->createInTransaction($administratorId, $input));
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['items' => $exception->getMessage()]];
        } catch (PDOException $exception) {
            if (in_array((string) $exception->getCode(), ['23505', '23000'], true)) {
                [, $conflicts] = $this->customerModes->prepare($input);
                if ($conflicts !== []) {
                    return ['success' => false, 'errors' => $conflicts];
                }
            }
            throw $exception;
        }
    }

    private function createInTransaction(int $administratorId, array $input): array
    {
        // Internal callers without a mode retain the established service contract.
        // HTTP forms always supply a mode, including an invalid empty mode if omitted.
        if (array_key_exists('customer_mode', $input)) {
            [$input, $modeErrors] = OrderCustomerMode::normalize($input);
            if ($modeErrors !== []) {
                return ['success' => false, 'errors' => $modeErrors];
            }
            if ($input['customer_mode'] === 'existing' && is_string($input['customer_id'] ?? null)) {
                $id = filter_var($input['customer_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $selected = $id === false ? null : $this->orders->findCustomer($id);
                if ($selected !== null) {
                    foreach (['customer_name', 'customer_phone', 'customer_email'] as $field) {
                        if (!array_key_exists('existing_' . $field, $input)) {
                            $input[$field] = (string) ($selected[$field] ?? '');
                        }
                    }
                }
            }
        }
        [$values, $errors] = $this->validator->validate($input);
        $rawCustomerId = $input['customer_id'] ?? '';
        $customerId = $rawCustomerId === '' ? null : (is_string($rawCustomerId)
            ? filter_var($rawCustomerId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false);
        if ($customerId === false || ($customerId !== null && $this->orders->findCustomer($customerId) === null)) {
            $errors['customer_id'] = 'Select an existing customer account or choose Guest order.';
        }
        if ($values['create_customer']) {
            if ($customerId !== null) {
                $errors['customer_id'] = 'Choose an existing account or create a new one, not both.';
            }
            if ($this->customers === null) {
                $errors['create_customer'] = 'Customer account creation is temporarily unavailable.';
            } else {
                $errors += $this->customerModes->conflicts($values);
            }
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        if ($values['create_customer']) {
            $customerId = $this->customerModes->createAccount($values, $administratorId);
        }
        return $this->insert($administratorId, $values, $customerId);
    }

    private function insert(int $administratorId, array $values, ?int $customerId): array
    {
        // WHAT: accessories ordered directly or inside a set must be available
        //       (shelf stock − other open orders), checked with the rows locked.
        $accessoryLines = [];
        foreach ($values['items'] as $item) {
            if ($item['product_id'] !== null) {
                $accessoryLines[$item['product_id']] = ($accessoryLines[$item['product_id']] ?? 0) + $item['quantity'];
            }
        }
        $this->accessories->assertAvailable($accessoryLines);
        // WHAT: the agreed scent of each catalogue item must still have enough
        //       fragrance material (custom-recipe items name their own materials).
        $scentLines = [];
        foreach ($values['items'] as $item) {
            if ($item['product_id'] !== null && ($item['recipe'] === [] || $item['recipe_source'] === 'CATALOGUE')) {
                $scentLines[] = ['product_id' => $item['product_id'], 'scent_id' => $item['scent_id'], 'quantity' => $item['quantity']];
            }
        }
        $this->scents->assertAvailable($scentLines);

        $orderNumber = ($this->orderNumberGenerator)();
        if (!is_string($orderNumber) || preg_match('/^[A-Z0-9-]{8,30}$/', $orderNumber) !== 1) {
            throw new \RuntimeException('The order number generator returned an invalid value.');
        }

        // Money stays in integer thousandths during arithmetic. This avoids
        // binary floating-point rounding errors for three-decimal TND.
        $subtotalMillis = array_sum(array_column($values['items'], 'line_total_millis'));
        $totalMillis = $values['pricing_quote']['total_millis'];
        $depositMillis = $values['pricing_quote']['deposit_millis'];
        $money = Money::formatMillis(...);
        $orderId = $this->orders->insertManualOrder([
            'order_number' => $orderNumber,
            'administrator_id' => $administratorId,
            'customer_id' => $customerId,
            'delivery_method' => $values['delivery_method'],
            'required_date' => $values['required_date'],
            'customer_name' => $values['customer_name'],
            'customer_email' => $values['customer_email'],
            'customer_phone' => $values['customer_phone'],
            'street_address' => $values['street_address'],
            'delegation' => $values['delegation'],
            'governorate' => $values['governorate'],
            'postal_code' => $values['postal_code'],
            'subtotal' => $money($subtotalMillis),
            'delivery_fee' => $money($values['delivery_fee_millis']),
            'total_amount' => $money($totalMillis),
            'required_deposit' => $money($depositMillis),
            'customer_notes' => $values['customer_notes'],
            'administrator_notes' => $values['administrator_notes'],
        ] + OrderPricingService::snapshot($values['pricing_quote'], array_sum(array_map(static fn (array $item): int =>
            $item['price_override_reason'] === null ? ($item['price_audit']['savings_millis'] ?? 0) : 0, $values['items']))));

        foreach ($values['items'] as $item) {
            $itemId = $this->orders->insertManualOrderItem([
                'catalogue_unit_price_snapshot' => $item['price_audit'] === null ? null : $money($item['price_audit']['base_millis']),
                'quantity_unit_price_snapshot' => $item['price_audit'] === null ? null : $money($item['price_audit']['quantity_millis']),
                'quantity_tier_snapshot' => $item['price_audit']['tier'] ?? null,
                'price_override_reason' => $item['price_override_reason'], 'recipe_source' => $item['recipe_source'],
                'order_id' => $orderId,
                'product_id' => $item['product_id'],
                'product_name' => $item['product_name'],
                'color_id' => $item['color_id'],
                'color_name' => $item['color_name'],
                'scent_id' => $item['scent_id'],
                'scent_name' => $item['scent_name'],
                'quantity' => $item['quantity'],
                'unit_price' => $money($item['unit_price_millis']),
                'unit_cost' => $item['unit_cost'],
                'line_total' => $money($item['line_total_millis']),
                'customization_notes' => $item['customization_notes'],
            ]);
            // The recipe is inserted in the same transaction as its item.
            $this->production->insertRecipe($itemId, $item['recipe']);
        }
        $this->orders->insertManualOrderHistory($orderId, $administratorId);

        return ['success' => true, 'errors' => [], 'orderId' => $orderId, 'orderNumber' => $orderNumber];
    }

    /** Orderable products and active materials for the new-order form. */
    public function formOptions(): array
    {
        return ['products' => $this->catalogue->findOrderableProducts(), 'materials' => $this->production->materials()];
    }

    /** Read-only authoritative preview; persistence revalidates all fields again. */
    public function preview(array $input): array
    {
        try {
            $rawItems = is_array($input['items'] ?? null) ? array_slice($input['items'], 0, 51) : [];
            if ($rawItems === [] || count($rawItems) > 50) {
                throw new DomainException('Add between one and 50 catalogue items.');
            }
            $products = $this->catalogue->findOrderableProducts();
            $lines = [];
            $subtotal = 0;
            $savings = 0;
            $cost = 0;
            $completeOptions = true;
            foreach ($rawItems as $raw) {
                $id = is_array($raw) && is_string($raw['product_id'] ?? null) ? ManualOrderValidator::positiveId($raw['product_id']) : null;
                $quantity = is_array($raw) && is_string($raw['quantity'] ?? null) ? filter_var($raw['quantity'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]) : false;
                if ($id === null || !isset($products[$id]) || $quantity === false) {
                    throw new DomainException('Choose an active catalogue product and a valid quantity.');
                }
                $price = OrderPricingService::unitPrice($products[$id], $quantity);
                $scentId = null;
                $lineOptionsComplete = true;
                foreach (['color_id' => 'colors','scent_id' => 'scents'] as $field => $options) {
                    // Missing choices do not block sale-price previews; saving still requires them.
                    $value = is_string($raw[$field] ?? null) ? $raw[$field] : '';
                    if ($value === '' && $products[$id][$options] !== []) {
                        $lineOptionsComplete = false;
                        $completeOptions = false;
                        continue;
                    }
                    [$optionId,,$optionError] = ManualOrderValidator::option($value, $products[$id][$options], $products[$id]['name'], $field === 'color_id' ? 'color' : 'scent');
                    if ($optionError !== null) {
                        throw new DomainException($optionError);
                    }
                    if ($field === 'scent_id') {
                        $scentId = $optionId;
                    }
                }
                $recipe = $lineOptionsComplete ? $this->production->catalogueRecipe($id, $scentId) : [];
                $effective = $price['quantity_millis'];
                $savings += $price['savings_millis'];
                $cost += $this->production->recipeCostMillis($recipe) * $quantity;
                $subtotal += $effective * $quantity;
                $lines[] = ['base' => Money::formatMillis($price['base_millis']), 'effective' => Money::formatMillis($effective),
                    'bulk_from' => OrderPricingService::bulkFrom($products[$id]), 'quantity_price' => Money::formatMillis($price['quantity_millis']), 'tier' => $price['tier'],
                    'line' => Money::formatMillis($effective * $quantity)];
            }
            $method = $input['delivery_method'] ?? '';
            $fee = is_string($input['delivery_fee'] ?? null) ? Money::parseMillis($input['delivery_fee']) : null;
            if (!in_array($method, ['PICKUP', 'DELIVERY'], true) || $fee === null || ($method === 'PICKUP' && $fee !== 0) || ($method === 'DELIVERY' && $fee < 9000)) {
                throw new DomainException('Review the delivery method and fee.');
            }
            $quote = (new OrderPricingService($this->catalogue->pricingRepository()))->quote($subtotal, $fee, $input);
            $amounts = [];
            foreach (['subtotal','promo','manual','delivery','total','deposit'] as $key) {
                $amounts[$key] = Money::formatMillis($quote[$key . '_millis']);
            }
            return ['success' => true, 'lines' => $lines, 'amounts' => $amounts, 'savings' => Money::formatMillis($savings), 'promo_code' => $quote['promo_code_snapshot'],
                'notice' => $completeOptions ? '' : 'Select the configured product options to estimate production cost.',
                'admin_estimate' => $completeOptions ? AdminReportService::grossEstimate($quote['total_millis'] - $fee, $cost) : null, 'promo_description' => $quote['promo_code_snapshot'] === null ? '' : $quote['promo_value_snapshot'] . ($quote['promo_type_snapshot'] === 'PERCENTAGE' ? '% discount' : ' TND discount')];
        } catch (DomainException $error) {
            return ['success' => false, 'error' => $error->getMessage()];
        }
    }
}
