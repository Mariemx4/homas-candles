<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Validate and atomically create orders recorded by an administrator.
 *
 * DATA FLOW: The HTTP controller supplies untrusted form values. This service
 * normalizes them, resolves catalogue items against the live catalogue,
 * calculates authoritative totals, and asks the repositories to save the
 * order, its items (with their custom production recipes), and its first
 * status-history event together.
 *
 * Catalogue entry uses authoritative prices/options and captures per-unit recipes.
 * Browser prices and recipes are ignored unless an explicit audited price override
 * is selected. One-off products are entered through Custom Requests; the trusted
 * internal custom-item contract remains available to established service callers.
 */

namespace App\Services;

use App\Database\TransactionManager;
use App\Repositories\CustomerAccountRepository;
use App\Repositories\OrderProductionRepository;
use App\Repositories\OrderRepository;
use Closure;
use DateTimeImmutable;
use DomainException;
use PDOException;

final class ManualOrderService
{
    // A Closure gives the production code a secure generator while allowing a
    // deterministic generator to be supplied by a focused automated test.
    private readonly Closure $orderNumberGenerator;

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly TransactionManager $transactions,
        private readonly OrderProductionRepository $catalogue,
        private readonly OrderProductionService $production,
        private readonly AccessoryStockService $accessories,
        private readonly ScentAvailabilityService $scents,
        ?callable $orderNumberGenerator = null,
        private readonly ?CustomerAccountRepository $customers = null
    ) {
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
                [, $conflicts] = (new CustomerModeService($this->orders, $this->customers))->prepare($input);
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
            if ($modeErrors !== []) { return ['success' => false, 'errors' => $modeErrors]; }
            if ($input['customer_mode'] === 'existing' && is_string($input['customer_id'] ?? null)) {
                $id = filter_var($input['customer_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $selected = $id === false ? null : $this->orders->findCustomer($id);
                if ($selected !== null) {
                    foreach (['customer_name', 'customer_phone', 'customer_email'] as $field) {
                        if (!array_key_exists('existing_' . $field, $input)) { $input[$field] = (string) ($selected[$field] ?? ''); }
                    }
                }
            }
        }
        [$values, $errors] = $this->validate($input);
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
                $errors += $this->accountConflictErrors($values);
            }
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        if ($values['create_customer']) {
            $customerId = (new CustomerModeService($this->orders, $this->customers))->createAccount($values);
        }
        return $this->insert($administratorId, $values, $customerId);
    }

    /** Refuse duplicate accounts; an administrator must explicitly select the existing account. */
    private function accountConflictErrors(array $values): array
    {
        return (new CustomerModeService($this->orders, $this->customers))->conflicts($values);
    }

    /**
     * FUNCTION PURPOSE: Save a validated manual order; runs inside create()'s transaction.
     *
     * @param array<string, mixed> $values
     * @return array{success: bool, errors: array<string, string>, orderId: int, orderNumber: string}
     */
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

    /**
     * Return normalized values and all field errors in one pass.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function validate(array $input): array
    {
        $errors = [];
        $string = static fn (array $source, string $key): string => isset($source[$key]) && is_string($source[$key]) ? trim($source[$key]) : '';
        $createCustomer = $string($input, 'create_customer') === '1';
        if (!in_array($string($input, 'create_customer'), ['', '0', '1'], true)) {
            $errors['create_customer'] = 'Choose a valid customer account option.';
        }
        $email = $string($input, 'customer_email');
        if ($createCustomer) {
            $email = strtolower($email);
        }
        $name = $string($input, 'customer_name');
        if ($createCustomer && $name === '') {
            // Administrators may register a contact with email, phones and address only.
            $name = function_exists('mb_substr') ? mb_substr(explode('@', $email, 2)[0], 0, 100, 'UTF-8') : substr(explode('@', $email, 2)[0], 0, 100);
        }
        if ($name === '' || $this->length($name) > 201) {
            $errors['customer_name'] = 'Enter a customer name of no more than 201 characters.';
        }
        if ($createCustomer && $email === '') {
            $errors['customer_email'] = 'An email address is required for a new account.';
        }
        if ($email !== '' && ($this->length($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['customer_email'] = $createCustomer ? 'Enter a valid email address for the new account.' : 'Enter a valid email address or leave it blank.';
        }
        $phone = $string($input, 'customer_phone');
        if (preg_match('/^[0-9]{8}$/', $phone) !== 1) {
            $errors['customer_phone'] = 'Enter an eight-digit phone number.';
        }
        $secondPhone = $createCustomer ? $string($input, 'customer_second_phone') : '';
        if ($secondPhone !== '' && (preg_match('/^[0-9]{8}$/', $secondPhone) !== 1 || $secondPhone === $phone)) {
            $errors['customer_second_phone'] = 'Enter a different eight-digit secondary phone number or leave it blank.';
        }
        [$firstName, $lastName] = array_pad(preg_split('/\s+/u', $name, 2) ?: [], 2, '');
        if ($createCustomer && ($this->length($firstName) > 100 || $this->length($lastName) > 100)) {
            $errors['customer_name'] = 'The first and remaining names must each be 100 characters or fewer.';
        }

        $requiredDate = $string($input, 'required_date');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $requiredDate);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $requiredDate) {
            $errors['required_date'] = 'Select a valid required date.';
        }
        $method = strtoupper($string($input, 'delivery_method'));
        if (!in_array($method, ['DELIVERY', 'PICKUP'], true)) {
            $errors['delivery_method'] = 'Select delivery or pickup.';
        }
        $fee = Money::parseMillis($string($input, 'delivery_fee'));
        if ($fee === null || ($method === 'PICKUP' && $fee !== 0) || ($method === 'DELIVERY' && $fee < 9000)) {
            $errors['delivery_fee'] = $method === 'DELIVERY' ? 'Delivery costs at least 9.000 TND.' : 'Pickup must have a zero delivery fee.';
            $fee = 0;
        }

        $validateAddress = function (string $prefix, bool $required) use ($input, $string, &$errors): array {
            $address = [];
            foreach (['street_address' => 255, 'delegation' => 100, 'governorate' => 100] as $key => $maximum) {
                $address[$key] = $string($input, $prefix . $key);
                if ($this->length($address[$key]) > $maximum || ($required && $address[$key] === '')) {
                    $errors[$prefix . $key] = 'Enter an address within the allowed length.';
                }
            }
            $address['postal_code'] = $string($input, $prefix . 'postal_code');
            if (($address['postal_code'] !== '' && preg_match('/^[0-9]{4}$/', $address['postal_code']) !== 1) || ($required && $address['postal_code'] === '')) {
                $errors[$prefix . 'postal_code'] = 'Enter a four-digit postal code.';
            }
            return $address;
        };
        $separateAccountAddress = $createCustomer && ($input['customer_mode'] ?? null) === 'new';
        $address = $validateAddress('', $method === 'DELIVERY' || ($createCustomer && !$separateAccountAddress));
        $accountAddress = $separateAccountAddress ? $validateAddress('account_', true) : $address;
        $postalCode = $address['postal_code'];

        $rawItems = isset($input['items']) && is_array($input['items']) ? array_values($input['items']) : [];
        if ($rawItems === [] || count($rawItems) > 50) {
            $errors['items'] = 'Add between one and 50 order items.';
        }
        // SETUP STEP: Load the live catalogue entries referenced by the items and
        //             the active materials, once, before validating each line.
        $referencedIds = [];
        foreach ($rawItems as $rawItem) {
            $id = is_array($rawItem) ? $this->positiveId($string($rawItem, 'product_id')) : null;
            if ($id !== null) {
                $referencedIds[$id] = $id;
            }
        }
        $products = $this->catalogue->findOrderableProducts(array_values($referencedIds));
        $materials = $this->production->materials();

        $items = [];
        foreach ($rawItems as $index => $rawItem) {
            if (!is_array($rawItem)) {
                $errors['items'] = 'One or more order items is invalid.';
                continue;
            }
            // A line may collect several problems; the last one is shown next to it.
            $itemError = null;
            $productText = $string($rawItem, 'product_id');
            $productId = $this->positiveId($productText);
            $product = $productId === null ? null : ($products[$productId] ?? null);
            $quantity = filter_var($string($rawItem, 'quantity'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
            $priceText = $string($rawItem, 'unit_price');
            $unitPrice = Money::parseMillis($priceText);
            $customization = $string($rawItem, 'customization_notes');
            [$recipe, $recipeError] = $product === null
                ? $this->production->parseRecipe($rawItem['recipe'] ?? null, $materials) : [[], null];
            $priceAudit = null;
            $overrideReason = null;
            $colorId = $scentId = null;
            $colorName = $scentName = null;

            if ($productText !== '' && $product === null) {
                // Unknown, inactive, or malformed product IDs are all refused.
                $itemError = 'Select an active catalogue product, or choose Custom item.';
            }
            if ($quantity === false) {
                $itemError = 'Every item needs a whole quantity greater than zero.';
                $quantity = 0;
            }

            if ($product !== null) {
                // CATALOGUE ITEM: the name, options and cost come from the catalogue.
                $productName = $product['name'];
                if ($quantity !== 0 && $quantity < $product['minimum_quantity']) {
                    $itemError = $productName . ' must be ordered in a quantity of at least ' . $product['minimum_quantity'] . '.';
                }
                [$colorId, $colorName, $optionError] = $this->option($string($rawItem, 'color_id'), $product['colors'], $productName, 'color');
                $itemError = $optionError ?? $itemError;
                [$scentId, $scentName, $optionError] = $this->option($string($rawItem, 'scent_id'), $product['scents'], $productName, 'scent');
                $itemError = $optionError ?? $itemError;
                try {
                    $priceAudit = OrderPricingService::unitPrice($product, (int) $quantity);
                    $unitPrice = $priceAudit['quantity_millis'];
                    $recipe = $this->production->catalogueRecipe($product['id'], $scentId);
                    if ($string($rawItem, 'price_override') === '1') {
                        $overrideReason = $string($rawItem, 'price_override_reason');
                        $override = Money::parseMillis($priceText);
                        if ($override === null || $override <= 0 || $overrideReason === '' || $this->length($overrideReason) > 500) {
                            throw new DomainException('A price override needs a positive three-decimal price and a reason of up to 500 characters.');
                        }
                        $unitPrice = $override;
                    }
                } catch (DomainException $exception) { $itemError = $exception->getMessage(); }
            } else {
                if (($input['catalogue_only'] ?? false) === true) $itemError = 'Select a catalogue product. Record a one-off design through Custom Requests.';
                // CUSTOM ITEM: free name and price, and an explicit recipe is mandatory.
                $productName = $string($rawItem, 'product_name');
                if ($productName === '' || $this->length($productName) > 180) {
                    $itemError = 'Every custom item needs a product name of no more than 180 characters.';
                }
                if ($recipeError === null && $recipe === []) {
                    $itemError = 'A custom item needs a production recipe with at least one material.';
                }
            }
            if ($unitPrice === null || $unitPrice <= 0) {
                $itemError = 'Every item needs a unit price greater than zero with up to three decimals.';
                $unitPrice = 0;
            }
            if ($recipeError !== null) {
                $itemError = $recipeError;
            }
            if ($this->length($customization) > 10000) {
                $itemError = 'Item customization notes must be 10,000 characters or fewer.';
            }
            $lineTotal = (int) $quantity * $unitPrice;
            if ($lineTotal > 999999999999) {
                $itemError = 'An item total exceeds the supported monetary limit.';
            }
            if ($itemError !== null) {
                $errors['items_' . $index] = $itemError;
            }

            // COST SNAPSHOT: a custom recipe defines the cost; otherwise the
            // catalogue's current production cost (migration 016) is frozen.
            $unitCost = $recipe !== []
                ? OrderProductionService::formatDecimal($this->production->recipeCostMillis($recipe))
                : $product['actual_cost'] ?? null;

            $items[] = [
                'price_audit' => $priceAudit,
                'price_override_reason' => $overrideReason,
                'recipe_source' => $product === null ? 'CUSTOM' : 'CATALOGUE',
                'product_id' => $product['id'] ?? null,
                'product_name' => $productName,
                'color_id' => $colorId,
                'color_name' => $colorName,
                'scent_id' => $scentId,
                'scent_name' => $scentName,
                'quantity' => (int) $quantity,
                'unit_price_millis' => $unitPrice,
                'unit_cost' => $unitCost,
                'line_total_millis' => $lineTotal,
                'customization_notes' => $customization === '' ? null : $customization,
                'recipe' => $recipe,
            ];
        }
        if (array_sum(array_column($items, 'line_total_millis')) + $fee > 999999999999) {
            $errors['items'] = 'The order total exceeds the supported monetary limit.';
        }

        $pricingInput = array_intersect_key($input, array_flip(['promo_code', 'manual_discount_type', 'manual_discount_value', 'manual_discount_reason']));
        try {
            $pricingQuote = (new OrderPricingService($this->catalogue->pricingRepository()))->quote(array_sum(array_column($items, 'line_total_millis')), $fee, $pricingInput, true);
        } catch (DomainException $exception) { $errors['discounts'] = $exception->getMessage(); $pricingQuote = null; }
        $customerNotes = $string($input, 'customer_notes');
        $administratorNotes = $string($input, 'administrator_notes');
        if ($this->length($customerNotes) > 10000) {
            $errors['customer_notes'] = 'Customer notes must be 10,000 characters or fewer.';
        }
        if ($this->length($administratorNotes) > 10000) {
            $errors['administrator_notes'] = 'Administrator notes must be 10,000 characters or fewer.';
        }

        return [[
            'pricing_quote' => $pricingQuote,
            'create_customer' => $createCustomer,
            'customer_second_phone' => $secondPhone === '' ? null : $secondPhone,
            'account_first_name' => $firstName,
            'account_last_name' => $lastName,
            'account_address' => $accountAddress,
            'customer_name' => $name,
            'customer_email' => $email === '' ? null : $email,
            'customer_phone' => $phone,
            'delivery_method' => $method,
            'required_date' => $requiredDate,
            'delivery_fee_millis' => $fee,
            'street_address' => $method === 'DELIVERY' ? $address['street_address'] : null,
            'delegation' => $method === 'DELIVERY' ? $address['delegation'] : null,
            'governorate' => $method === 'DELIVERY' ? $address['governorate'] : null,
            'postal_code' => $method === 'DELIVERY' ? $postalCode : null,
            'items' => $items,
            'customer_notes' => $customerNotes === '' ? null : $customerNotes,
            'administrator_notes' => $administratorNotes === '' ? null : $administratorNotes,
        ], $errors];
    }

    /**
     * FUNCTION PURPOSE: Supply the catalogue and materials the manual-order form offers.
     *
     * @return array{products: array<int, array<string, mixed>>, materials: array<int, array<string, mixed>>}
     */
    public function formOptions(): array
    {
        return ['products' => $this->catalogue->findOrderableProducts(), 'materials' => $this->production->materials()];
    }

    /** Read-only authoritative preview; persistence revalidates all fields again. */
    public function preview(array $input): array
    {
        try {
            $rawItems = is_array($input['items'] ?? null) ? array_slice($input['items'], 0, 51) : [];
            if ($rawItems === [] || count($rawItems) > 50) throw new DomainException('Add between one and 50 catalogue items.');
            $products = $this->catalogue->findOrderableProducts(); $lines = []; $subtotal = 0; $savings = 0;
            foreach ($rawItems as $raw) {
                $id = is_array($raw) && is_string($raw['product_id'] ?? null) ? $this->positiveId($raw['product_id']) : null;
                $quantity = is_array($raw) && is_string($raw['quantity'] ?? null) ? filter_var($raw['quantity'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]) : false;
                if ($id === null || !isset($products[$id]) || $quantity === false) throw new DomainException('Choose an active catalogue product and a valid quantity.');
                $price = OrderPricingService::unitPrice($products[$id], $quantity);
                $this->production->catalogueRecipe($id, is_string($raw['scent_id'] ?? null) ? $this->positiveId($raw['scent_id']) : null);
                $effective = $price['quantity_millis'];
                if (($raw['price_override'] ?? '') === '1') {
                    $effective = is_string($raw['unit_price'] ?? null) ? Money::parseMillis($raw['unit_price']) : null;
                    if ($effective === null || $effective <= 0 || !is_string($raw['price_override_reason'] ?? null) || trim($raw['price_override_reason']) === '') throw new DomainException('Enter a valid override price and reason.');
                } else { $savings += $price['savings_millis']; }
                $subtotal += $effective * $quantity;
                $lines[] = ['base' => Money::formatMillis($price['base_millis']), 'effective' => Money::formatMillis($effective),
                    'quantity_price' => Money::formatMillis($price['quantity_millis']), 'tier' => $price['tier'],
                    'line' => Money::formatMillis($effective * $quantity)];
            }
            $method = $input['delivery_method'] ?? '';
            $fee = is_string($input['delivery_fee'] ?? null) ? Money::parseMillis($input['delivery_fee']) : null;
            if (!in_array($method, ['PICKUP', 'DELIVERY'], true) || $fee === null || ($method === 'PICKUP' && $fee !== 0) || ($method === 'DELIVERY' && $fee < 9000)) throw new DomainException('Review the delivery method and fee.');
            $quote = (new OrderPricingService($this->catalogue->pricingRepository()))->quote($subtotal, $fee, $input);
            $amounts = [];
            foreach (['subtotal','promo','manual','delivery','total','deposit'] as $key) $amounts[$key] = Money::formatMillis($quote[$key . '_millis']);
            return ['success' => true, 'lines' => $lines, 'amounts' => $amounts, 'savings' => Money::formatMillis($savings), 'promo_code' => $quote['promo_code_snapshot'],
                'promo_description' => $quote['promo_code_snapshot'] === null ? '' : $quote['promo_value_snapshot'] . ($quote['promo_type_snapshot'] === 'PERCENTAGE' ? '% discount' : ' TND discount')];
        } catch (DomainException $error) { return ['success' => false, 'error' => $error->getMessage()]; }
    }

    /**
     * FUNCTION PURPOSE: Resolve one agreed option (color or scent) of a catalogue item.
     *
     * WHAT: when the product offers active options, one of them is required;
     *       when it offers none, the field must stay empty.
     * WHY:  production makes what the snapshot says, so an order line may only
     *       carry an option the product really sells — the same rule checkout
     *       applies through the cart.
     *
     * @param array<int, string> $allowed Active option IDs => English names.
     * @return array{0: ?int, 1: ?string, 2: ?string} ID, name snapshot, error.
     */
    private function option(string $value, array $allowed, string $productName, string $label): array
    {
        if ($allowed === []) {
            return $value === ''
                ? [null, null, null]
                : [null, null, $productName . ' has no ' . $label . ' options.'];
        }
        $id = $this->positiveId($value);
        if ($id === null || !isset($allowed[$id])) {
            return [null, null, 'Select an available ' . $label . ' for ' . $productName . '.'];
        }

        return [$id, $allowed[$id], null];
    }

    /**
     * FUNCTION PURPOSE: Convert a submitted ID into a positive integer, or null when absent/invalid.
     */
    private function positiveId(string $value): ?int
    {
        // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
        $id = $value === '' ? false : filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : (int) $id;
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
