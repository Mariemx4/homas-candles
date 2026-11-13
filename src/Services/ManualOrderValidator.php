<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\FormInput;
use App\Repositories\OrderProductionRepository;
use DateTimeImmutable;
use DomainException;

/**
 * Checks and normalizes the "new order" form an administrator submits.
 *
 * validate() returns [values, errors]: every field is checked in one pass so
 * the form can show all problems at once. Nothing is saved here; the caller
 * (ManualOrderService) inserts the order only when errors is empty.
 *
 * Money is kept in integer thousandths of a dinar ("millis") to avoid
 * floating-point rounding.
 */
final class ManualOrderValidator
{
    private const MONEY_LIMIT_MILLIS = 999999999999;
    private const MINIMUM_DELIVERY_FEE_MILLIS = 9000;

    public function __construct(
        private readonly OrderProductionRepository $catalogue,
        private readonly OrderProductionService $production
    ) {
    }

    /**
     * Return normalized values and all field errors in one pass.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public function validate(array $input): array
    {
        $errors = [];
        $customer = $this->customer($input, $errors);
        [$requiredDate, $method, $fee] = $this->schedule($input, $errors);

        // A new account entered in "new customer" mode has its own address
        // fields (account_*); otherwise the delivery address doubles as it.
        $separateAccountAddress = $customer['create'] && ($input['customer_mode'] ?? null) === 'new';
        $address = $this->address($input, '', $method === 'DELIVERY' || ($customer['create'] && !$separateAccountAddress), $errors);
        $accountAddress = $separateAccountAddress ? $this->address($input, 'account_', true, $errors) : $address;

        $items = $this->items($input, $errors);
        if (array_sum(array_column($items, 'line_total_millis')) + $fee > self::MONEY_LIMIT_MILLIS) {
            $errors['items'] = 'The order total exceeds the supported monetary limit.';
        }

        $pricingInput = array_intersect_key($input, array_flip(['promo_code', 'manual_discount_type', 'manual_discount_value', 'manual_discount_reason']));
        try {
            $pricingQuote = (new OrderPricingService($this->catalogue->pricingRepository()))->quote(array_sum(array_column($items, 'line_total_millis')), $fee, $pricingInput, true);
        } catch (DomainException $exception) {
            $errors['discounts'] = $exception->getMessage();
            $pricingQuote = null;
        }
        $customerNotes = FormInput::trimmed($input, 'customer_notes');
        $administratorNotes = FormInput::trimmed($input, 'administrator_notes');
        if (Text::length($customerNotes) > 10000) {
            $errors['customer_notes'] = 'Customer notes must be 10,000 characters or fewer.';
        }
        if (Text::length($administratorNotes) > 10000) {
            $errors['administrator_notes'] = 'Administrator notes must be 10,000 characters or fewer.';
        }

        return [[
            'pricing_quote' => $pricingQuote,
            'create_customer' => $customer['create'],
            'customer_second_phone' => $customer['second_phone'] === '' ? null : $customer['second_phone'],
            'account_first_name' => $customer['first_name'],
            'account_last_name' => $customer['last_name'],
            'account_address' => $accountAddress,
            'customer_name' => $customer['name'],
            'customer_email' => $customer['email'] === '' ? null : $customer['email'],
            'customer_phone' => $customer['phone'],
            'delivery_method' => $method,
            'required_date' => $requiredDate,
            'delivery_fee_millis' => $fee,
            'street_address' => $method === 'DELIVERY' ? $address['street_address'] : null,
            'delegation' => $method === 'DELIVERY' ? $address['delegation'] : null,
            'governorate' => $method === 'DELIVERY' ? $address['governorate'] : null,
            'postal_code' => $method === 'DELIVERY' ? $address['postal_code'] : null,
            'items' => $items,
            'customer_notes' => $customerNotes === '' ? null : $customerNotes,
            'administrator_notes' => $administratorNotes === '' ? null : $administratorNotes,
        ], $errors];
    }

    /**
     * Returns [id, name, error] for a color/scent choice against a product's allowed options.
     *
     * @param array<int, string> $allowed
     * @return array{0: ?int, 1: ?string, 2: ?string}
     */
    public static function option(string $value, array $allowed, string $productName, string $label): array
    {
        if ($allowed === []) {
            return $value === ''
                ? [null, null, null]
                : [null, null, $productName . ' has no ' . $label . ' options.'];
        }
        $id = self::positiveId($value);
        if ($id === null || !isset($allowed[$id])) {
            return [null, null, 'Select an available ' . $label . ' for ' . $productName . '.'];
        }

        return [$id, $allowed[$id], null];
    }

    /** A form value as a positive integer ID, or null when it is empty or not one. */
    public static function positiveId(string $value): ?int
    {
        $id = $value === '' ? false : filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : (int) $id;
    }

    /**
     * Customer contact details, and the extra rules when a new account is created.
     *
     * @return array{create: bool, name: string, email: string, phone: string, second_phone: string, first_name: string, last_name: string}
     */
    private function customer(array $input, array &$errors): array
    {
        $createCustomer = FormInput::trimmed($input, 'create_customer') === '1';
        if (!in_array(FormInput::trimmed($input, 'create_customer'), ['', '0', '1'], true)) {
            $errors['create_customer'] = 'Choose a valid customer account option.';
        }
        $email = FormInput::trimmed($input, 'customer_email');
        if ($createCustomer) {
            $email = strtolower($email);
        }
        $name = FormInput::trimmed($input, 'customer_name');
        if ($createCustomer && $name === '') {
            // Administrators may register a contact with email, phones and address only.
            $name = function_exists('mb_substr') ? mb_substr(explode('@', $email, 2)[0], 0, 100, 'UTF-8') : substr(explode('@', $email, 2)[0], 0, 100);
        }
        if ($name === '' || Text::length($name) > 201) {
            $errors['customer_name'] = 'Enter a customer name of no more than 201 characters.';
        }
        if ($createCustomer && $email === '') {
            $errors['customer_email'] = 'An email address is required for a new account.';
        }
        if ($email !== '' && (Text::length($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['customer_email'] = $createCustomer ? 'Enter a valid email address for the new account.' : 'Enter a valid email address or leave it blank.';
        }
        $phone = FormInput::trimmed($input, 'customer_phone');
        if (preg_match('/^[0-9]{8}$/', $phone) !== 1) {
            $errors['customer_phone'] = 'Enter an eight-digit phone number.';
        }
        $secondPhone = $createCustomer ? FormInput::trimmed($input, 'customer_second_phone') : '';
        if ($secondPhone !== '' && (preg_match('/^[0-9]{8}$/', $secondPhone) !== 1 || $secondPhone === $phone)) {
            $errors['customer_second_phone'] = 'Enter a different eight-digit secondary phone number or leave it blank.';
        }
        [$firstName, $lastName] = array_pad(preg_split('/\s+/u', $name, 2) ?: [], 2, '');
        if ($createCustomer && (Text::length($firstName) > 100 || Text::length($lastName) > 100)) {
            $errors['customer_name'] = 'The first and remaining names must each be 100 characters or fewer.';
        }

        return [
            'create' => $createCustomer,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'second_phone' => $secondPhone,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ];
    }

    /**
     * Required date, delivery method and delivery fee (pickup is free; delivery costs at least 9 TND).
     *
     * @return array{0: string, 1: string, 2: int} [required date, method, fee in millis]
     */
    private function schedule(array $input, array &$errors): array
    {
        $requiredDate = FormInput::trimmed($input, 'required_date');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $requiredDate);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $requiredDate) {
            $errors['required_date'] = 'Select a valid required date.';
        }
        $method = strtoupper(FormInput::trimmed($input, 'delivery_method'));
        if (!in_array($method, ['DELIVERY', 'PICKUP'], true)) {
            $errors['delivery_method'] = 'Select delivery or pickup.';
        }
        $fee = Money::parseMillis(FormInput::trimmed($input, 'delivery_fee'));
        if ($fee === null || ($method === 'PICKUP' && $fee !== 0) || ($method === 'DELIVERY' && $fee < self::MINIMUM_DELIVERY_FEE_MILLIS)) {
            $errors['delivery_fee'] = $method === 'DELIVERY' ? 'Delivery costs at least 9.000 TND.' : 'Pickup must have a zero delivery fee.';
            $fee = 0;
        }

        return [$requiredDate, $method, $fee];
    }

    /**
     * One address block; $prefix selects the form fields ('' or 'account_').
     *
     * @return array{street_address: string, delegation: string, governorate: string, postal_code: string}
     */
    private function address(array $input, string $prefix, bool $required, array &$errors): array
    {
        $address = [];
        foreach (['street_address' => 255, 'delegation' => 100, 'governorate' => 100] as $key => $maximum) {
            $address[$key] = FormInput::trimmed($input, $prefix . $key);
            if (Text::length($address[$key]) > $maximum || ($required && $address[$key] === '')) {
                $errors[$prefix . $key] = 'Enter an address within the allowed length.';
            }
        }
        $address['postal_code'] = FormInput::trimmed($input, $prefix . 'postal_code');
        if (($address['postal_code'] !== '' && preg_match('/^[0-9]{4}$/', $address['postal_code']) !== 1) || ($required && $address['postal_code'] === '')) {
            $errors[$prefix . 'postal_code'] = 'Enter a four-digit postal code.';
        }

        return $address;
    }

    /**
     * All order lines. Products and materials are loaded once for the whole order.
     *
     * @return list<array<string, mixed>>
     */
    private function items(array $input, array &$errors): array
    {
        $rawItems = isset($input['items']) && is_array($input['items']) ? array_values($input['items']) : [];
        if ($rawItems === [] || count($rawItems) > 50) {
            $errors['items'] = 'Add between one and 50 order items.';
        }
        $referencedIds = [];
        foreach ($rawItems as $rawItem) {
            $id = is_array($rawItem) ? self::positiveId(FormInput::trimmed($rawItem, 'product_id')) : null;
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
            [$item, $itemError] = $this->item($rawItem, $input, $products, $materials);
            if ($itemError !== null) {
                $errors['items_' . $index] = $itemError;
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * One order line: a catalogue product (price, options and recipe come from
     * the catalogue) or a custom item (free name and price, explicit recipe).
     * A line may have several problems; the last one found is reported.
     *
     * @return array{0: array<string, mixed>, 1: ?string} [normalized line, error]
     */
    private function item(array $rawItem, array $input, array $products, array $materials): array
    {
        $itemError = null;
        $productText = FormInput::trimmed($rawItem, 'product_id');
        $productId = self::positiveId($productText);
        $product = $productId === null ? null : ($products[$productId] ?? null);
        $quantity = filter_var(FormInput::trimmed($rawItem, 'quantity'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        $unitPrice = Money::parseMillis(FormInput::trimmed($rawItem, 'unit_price'));
        $customization = FormInput::trimmed($rawItem, 'customization_notes');
        [$recipe, $recipeError] = $product === null
            ? $this->production->parseRecipe($rawItem['recipe'] ?? null, $materials) : [[], null];
        $priceAudit = null;
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
            $productName = $product['name'];
            if ($quantity !== 0 && $quantity < $product['minimum_quantity']) {
                $itemError = $productName . ' must be ordered in a quantity of at least ' . $product['minimum_quantity'] . '.';
            }
            [$colorId, $colorName, $optionError] = self::option(FormInput::trimmed($rawItem, 'color_id'), $product['colors'], $productName, 'color');
            $itemError = $optionError ?? $itemError;
            [$scentId, $scentName, $optionError] = self::option(FormInput::trimmed($rawItem, 'scent_id'), $product['scents'], $productName, 'scent');
            $itemError = $optionError ?? $itemError;
            try {
                $priceAudit = OrderPricingService::unitPrice($product, (int) $quantity);
                $unitPrice = $priceAudit['quantity_millis'];
                $recipe = $this->production->catalogueRecipe($product['id'], $scentId);
            } catch (DomainException $exception) {
                $itemError = $exception->getMessage();
            }
        } else {
            if (($input['catalogue_only'] ?? false) === true) {
                $itemError = 'Select a catalogue product. Record a one-off design through Custom Requests.';
            }
            $productName = FormInput::trimmed($rawItem, 'product_name');
            if ($productName === '' || Text::length($productName) > 180) {
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
        if (Text::length($customization) > 10000) {
            $itemError = 'Item customization notes must be 10,000 characters or fewer.';
        }
        $lineTotal = (int) $quantity * $unitPrice;
        if ($lineTotal > self::MONEY_LIMIT_MILLIS) {
            $itemError = 'An item total exceeds the supported monetary limit.';
        }

        // COST SNAPSHOT: a recipe defines the cost; otherwise the catalogue's
        // current production cost (migration 016) is frozen.
        $unitCost = $recipe !== []
            ? OrderProductionService::formatDecimal($this->production->recipeCostMillis($recipe))
            : $product['actual_cost'] ?? null;

        return [[
            'price_audit' => $priceAudit,
            'price_override_reason' => null,
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
        ], $itemError];
    }
}
