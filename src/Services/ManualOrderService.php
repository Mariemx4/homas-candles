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
 * Each item is either:
 *   - a CATALOGUE item: linked by product_id, with the agreed color/scent
 *     chosen from the product's active options, the product's name, its
 *     calculated price unless an agreed price is entered, and its production
 *     cost; an optional custom recipe replaces the catalogue recipe; or
 *   - a CUSTOM item: no product_id, a free product name and agreed price, and
 *     a REQUIRED explicit production recipe, so production and the PREPARED
 *     deduction know exactly which materials it consumes.
 */

namespace App\Services;

use App\Database\TransactionManager;
use App\Repositories\OrderProductionRepository;
use App\Repositories\OrderRepository;
use Closure;
use DateTimeImmutable;
use DomainException;

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
        ?callable $orderNumberGenerator = null
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
        [$values, $errors] = $this->validate($input);
        $rawCustomerId = $input['customer_id'] ?? '';
        $customerId = $rawCustomerId === '' ? null : (is_string($rawCustomerId)
            ? filter_var($rawCustomerId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false);
        if ($customerId === false || ($customerId !== null && $this->orders->findCustomer($customerId) === null)) {
            $errors['customer_id'] = 'Select an existing customer account or choose Guest order.';
        }
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        // A transaction prevents half an order: if any item or history insert
        // fails, TransactionManager rolls back the order row as well.
        try {
            return $this->transactions->run(fn (): array => $this->insert($administratorId, $values, $customerId));
        } catch (DomainException $exception) {
            // Accessory or fragrance stock ran out between the form and the save.
            return ['success' => false, 'errors' => ['items' => $exception->getMessage()]];
        }
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
            if ($item['product_id'] !== null && $item['recipe'] === []) {
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
        $totalMillis = $subtotalMillis + $values['delivery_fee_millis'];
        $depositMillis = $totalMillis >= 100000 ? intdiv(($totalMillis * 4) + 5, 10) : 0;
        $money = fn (int $millis): string => $this->millisToMoney($millis);
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
        ]);

        foreach ($values['items'] as $item) {
            $itemId = $this->orders->insertManualOrderItem([
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
        $name = $string($input, 'customer_name');
        if ($name === '' || $this->length($name) > 201) {
            $errors['customer_name'] = 'Enter a customer name of no more than 201 characters.';
        }
        $email = $string($input, 'customer_email');
        if ($email !== '' && ($this->length($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['customer_email'] = 'Enter a valid email address or leave it blank.';
        }
        $phone = $string($input, 'customer_phone');
        if (preg_match('/^[0-9]{8}$/', $phone) !== 1) {
            $errors['customer_phone'] = 'Enter an eight-digit phone number.';
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
        $fee = $this->parseMoney($string($input, 'delivery_fee'));
        if ($fee === null || ($method === 'PICKUP' && $fee !== 0) || ($method === 'DELIVERY' && $fee < 9000)) {
            $errors['delivery_fee'] = $method === 'DELIVERY' ? 'Delivery costs at least 9.000 TND.' : 'Pickup must have a zero delivery fee.';
            $fee = 0;
        }

        $address = [];
        foreach (['street_address' => 255, 'delegation' => 100, 'governorate' => 100] as $key => $maximum) {
            $address[$key] = $string($input, $key);
            if ($this->length($address[$key]) > $maximum || ($method === 'DELIVERY' && $address[$key] === '')) {
                $errors[$key] = 'This delivery address field is required and must fit the allowed length.';
            }
        }
        $postalCode = $string($input, 'postal_code');
        if (($postalCode !== '' && preg_match('/^[0-9]{4}$/', $postalCode) !== 1) || ($method === 'DELIVERY' && $postalCode === '')) {
            $errors['postal_code'] = 'Enter a four-digit postal code.';
        }

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
            $unitPrice = $this->parseMoney($priceText);
            $customization = $string($rawItem, 'customization_notes');
            [$recipe, $recipeError] = $this->production->parseRecipe($rawItem['recipe'] ?? null, $materials);
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
                if ($priceText === '') {
                    // No agreed price entered: use the authoritative calculated price.
                    $unitPrice = $product['selling_price'] === null ? null : $this->parseMoney($product['selling_price']);
                    if ($unitPrice === null || $unitPrice <= 0) {
                        $itemError = $productName . ' has no calculated price; enter the agreed unit price.';
                        $unitPrice = 0;
                    }
                }
            } else {
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

        $customerNotes = $string($input, 'customer_notes');
        $administratorNotes = $string($input, 'administrator_notes');
        if ($this->length($customerNotes) > 10000) {
            $errors['customer_notes'] = 'Customer notes must be 10,000 characters or fewer.';
        }
        if ($this->length($administratorNotes) > 10000) {
            $errors['administrator_notes'] = 'Administrator notes must be 10,000 characters or fewer.';
        }

        return [[
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

    private function parseMoney(string $value): ?int
    {
        // The regular expression accepts at most three decimal places, then the
        // value is converted to thousandths without ever casting to float.
        if (preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.([0-9]{1,3}))?$/', $value, $matches) !== 1) {
            return null;
        }
        return ((int) explode('.', $value)[0] * 1000) + (int) str_pad($matches[1] ?? '', 3, '0');
    }

    private function millisToMoney(int $millis): string
    {
        return intdiv($millis, 1000) . '.' . str_pad((string) ($millis % 1000), 3, '0', STR_PAD_LEFT);
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
