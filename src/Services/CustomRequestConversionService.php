<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\TransactionManager;
use App\Repositories\CustomRequestRepository;
use Closure;
use DateTimeImmutable;
use DomainException;

final class CustomRequestConversionService
{
    private readonly Closure $orderNumberGenerator;

    public function __construct(
        private readonly CustomRequestRepository $requests,
        private readonly TransactionManager $transactions,
        ?callable $orderNumberGenerator = null,
        private readonly ?OrderProductionService $production = null
    ) {
        $this->orderNumberGenerator = $orderNumberGenerator === null
            ? static fn (): string => 'ORD-M-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)))
            : Closure::fromCallable($orderNumberGenerator);
    }

    /**
     * Confirm an agreed request as one order while holding its database row lock.
     *
     * @param array<string, mixed> $input @return array{success: bool, errors: array<string, string>, orderId?: int, orderNumber?: string}
     */
    public function convert(int $requestId, int $administratorId, array $input): array
    {
        [$values, $errors] = $this->validate($input);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        try {
            return $this->transactions->run(function () use ($requestId, $administratorId, $values): array {
                $request = $this->requests->lockRequest($requestId);
                if ($request === null) {
                    throw new DomainException('The custom request could not be found.');
                }
                if ((string) $request['request_status'] !== 'AGREED' || $request['converted_order_id'] !== null) {
                    throw new DomainException('Only an agreed, unconverted request can become an order.');
                }
                $minimumQuantity = $this->minimumQuantity($request);
                if ($values['quantity'] < $minimumQuantity) {
                    return ['success' => false, 'errors' => [
                        'quantity' => 'Enter an agreed quantity of at least ' . $minimumQuantity . ' for this request.',
                    ]];
                }
                $orderNumber = ($this->orderNumberGenerator)();
                if (!is_string($orderNumber) || preg_match('/^[A-Z0-9-]{8,30}$/', $orderNumber) !== 1) {
                    throw new \RuntimeException('The order number generator returned an invalid value.');
                }
                $subtotalMillis = $values['unit_price_millis'] * $values['quantity'];
                $totalMillis = OrderPricingService::total($subtotalMillis, 0, 0, $values['delivery_fee_millis']);
                $depositMillis = OrderPricingService::deposit($totalMillis);
                $money = Money::formatMillis(...);
                $orderId = $this->requests->insertManualOrder([
                    'order_number' => $orderNumber,
                    'customer_id' => $request['customer_id'],
                    'administrator_id' => $administratorId,
                    'delivery_method' => $values['delivery_method'],
                    'required_date' => $values['required_date'],
                    'customer_name' => $request['customer_name_snapshot'],
                    'customer_email' => $request['customer_email_snapshot'],
                    'customer_phone' => $request['customer_phone_snapshot'],
                    'street_address' => $values['street_address'],
                    'delegation' => $values['delegation'],
                    'governorate' => $values['governorate'],
                    'postal_code' => $values['postal_code'],
                    'subtotal' => $money($subtotalMillis),
                    'delivery_fee' => $money($values['delivery_fee_millis']),
                    'total_amount' => $money($totalMillis),
                    'required_deposit' => $money($depositMillis),
                    'customer_notes' => $request['customer_message'],
                    'administrator_notes' => $values['administrator_notes'],
                ]);
                $recipe = [];
                $recipeSource = null;
                if ($this->production !== null && $request['product_id'] !== null) {
                    try {
                        $recipe = $this->production->catalogueRecipe((int) $request['product_id']);
                        // The request may have agreed one-off specifications; preserve its existing pre-production correction workflow.
                        $recipeSource = 'CUSTOM';
                    } catch (DomainException) {
                        // One-off requests retain their established explicit production-definition step.
                        $recipeSource = 'CUSTOM';
                    }
                }
                $itemId = $this->requests->insertOrderItem([
                    'recipe_source' => $recipeSource,

                    'order_id' => $orderId,
                    'product_id' => $request['product_id'],
                    'product_name' => $values['product_name'],
                    'quantity' => $values['quantity'],
                    'unit_price' => $money($values['unit_price_millis']),
                    'line_total' => $money($subtotalMillis),
                    'customization_notes' => $values['customization_notes'],
                ]);
                if ($recipe !== []) $this->production->insertRecipe($itemId, $recipe);
                $this->requests->insertInitialOrderHistory($orderId, $administratorId);
                $this->requests->markConverted($requestId, $orderId);

                return ['success' => true, 'errors' => [], 'orderId' => $orderId, 'orderNumber' => $orderNumber];
            });
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['conversion' => $exception->getMessage()]];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(array $request): array
    {
        $preferences = [];
        foreach (['color_preferences' => 'Color', 'scent_preferences' => 'Scent', 'packaging_preferences' => 'Packaging'] as $key => $label) {
            if (is_string($request[$key] ?? null) && trim($request[$key]) !== '') {
                $preferences[] = $label . ': ' . trim((string) $request[$key]);
            }
        }

        return [
            'product_name' => (string) ($request['product_name'] ?? $request['category_name'] ?? 'Custom product'),
            'quantity' => (string) ($request['requested_quantity'] ?? $this->minimumQuantity($request)),
            // The customer's total budget is a preference, never a confirmed batch or unit price.
            'agreed_batch_price' => '',
            'unit_price' => '',
            'customization_notes' => implode("\n", $preferences),
            'delivery_method' => 'PICKUP',
            'required_date' => (string) ($request['required_date'] ?? ''),
            'delivery_fee' => '0.000',
            'street_address' => '', 'delegation' => '', 'governorate' => '', 'postal_code' => '',
            'administrator_notes' => '',
        ];
    }

    /** @param array<string, mixed> $request */
    public function minimumQuantity(array $request): int
    {
        $categoryMinimum = ($request['category_slug'] ?? null) === 'event-favors'
            ? PricingService::EVENT_FAVOR_MINIMUM : 1;
        return max($categoryMinimum, (int) ($request['product_minimum_quantity'] ?? 1));
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function validate(array $input): array
    {
        $errors = [];
        $string = static fn (string $key): string => isset($input[$key]) && is_string($input[$key]) ? trim($input[$key]) : '';
        $productName = $string('product_name');
        if ($productName === '' || $this->length($productName) > 180) {
            $errors['product_name'] = 'Enter a product name of no more than 180 characters.';
        }
        $quantity = filter_var($string('quantity'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        if ($quantity === false) {
            $errors['quantity'] = 'Enter a whole quantity greater than zero.';
            $quantity = 0;
        }
        $unitPriceText = $string('unit_price');
        $batchPriceText = $string('agreed_batch_price');
        $unitPrice = 0;
        $priceField = $batchPriceText !== '' ? 'agreed_batch_price' : 'unit_price';
        if ($unitPriceText !== '' && $batchPriceText !== '') {
            $errors['agreed_batch_price'] = 'Enter either an agreed batch price or an agreed unit price, not both.';
        } elseif ($batchPriceText !== '') {
            $batchPrice = Money::parseMillis($batchPriceText);
            if ($batchPrice === null || $batchPrice <= 0) {
                $errors['agreed_batch_price'] = 'Enter an agreed batch price greater than zero, with up to three decimals.';
            } elseif ($quantity > 0) {
                // order_items requires line total = quantity * its three-decimal unit price.
                if ($batchPrice % (int) $quantity !== 0) {
                    $errors['agreed_batch_price'] = 'The batch price must divide exactly across the quantity at three decimals. Revise the batch price or enter an agreed unit price.';
                } else {
                    $unitPrice = intdiv($batchPrice, (int) $quantity);
                    if ($unitPrice === 0) {
                        $errors['agreed_batch_price'] = 'The batch price must be at least 0.001 TND per item.';
                    }
                }
            }
        } elseif ($unitPriceText === '') {
            $errors['unit_price'] = 'Enter an agreed batch price or an agreed unit price.';
        } else {
            $unitPrice = Money::parseMillis($unitPriceText) ?? 0;
            if ($unitPrice <= 0) {
                $errors['unit_price'] = 'Enter an agreed unit price greater than zero, with up to three decimals.';
            }
        }
        $method = strtoupper($string('delivery_method'));
        if (!in_array($method, ['DELIVERY', 'PICKUP'], true)) {
            $errors['delivery_method'] = 'Select delivery or pickup.';
        }
        $requiredDate = $string('required_date');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $requiredDate);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $requiredDate) {
            $errors['required_date'] = 'Select a valid required date.';
        }
        $fee = Money::parseMillis($string('delivery_fee'));
        if ($fee === null || ($method === 'PICKUP' && $fee !== 0) || ($method === 'DELIVERY' && $fee < 9000)) {
            $errors['delivery_fee'] = $method === 'DELIVERY'
                ? 'Delivery costs at least 9.000 TND.' : 'Pickup must have a zero delivery fee.';
            $fee = 0;
        }
        if ($quantity > 0 && $unitPrice > 0) {
            $subtotal = $unitPrice * (int) $quantity;
            if ($subtotal > 999999999999 || $subtotal + $fee > 999999999999) {
                $errors[$priceField] = 'The agreed order total exceeds the supported monetary limit.';
            }
        }
        $address = [];
        foreach (['street_address' => 255, 'delegation' => 100, 'governorate' => 100] as $key => $maximum) {
            $address[$key] = $string($key);
            if ($method === 'DELIVERY' && ($address[$key] === '' || $this->length($address[$key]) > $maximum)) {
                $errors[$key] = 'This delivery address field is required and must fit the allowed length.';
            }
        }
        $postalCode = $string('postal_code');
        if ($method === 'DELIVERY' && preg_match('/^[0-9]{4}$/', $postalCode) !== 1) {
            $errors['postal_code'] = 'Enter a four-digit postal code.';
        }
        $customization = $string('customization_notes');
        $adminNotes = $string('administrator_notes');
        if ($this->length($customization) > 10000) {
            $errors['customization_notes'] = 'Customization notes must be 10,000 characters or fewer.';
        }
        if ($this->length($adminNotes) > 10000) {
            $errors['administrator_notes'] = 'Administrator notes must be 10,000 characters or fewer.';
        }

        return [[
            'product_name' => $productName, 'quantity' => (int) $quantity, 'unit_price_millis' => $unitPrice,
            'customization_notes' => $customization === '' ? null : $customization,
            'delivery_method' => $method, 'required_date' => $requiredDate, 'delivery_fee_millis' => $fee,
            'street_address' => $method === 'DELIVERY' ? $address['street_address'] : null,
            'delegation' => $method === 'DELIVERY' ? $address['delegation'] : null,
            'governorate' => $method === 'DELIVERY' ? $address['governorate'] : null,
            'postal_code' => $method === 'DELIVERY' ? $postalCode : null,
            'administrator_notes' => $adminNotes === '' ? null : $adminNotes,
        ], $errors];
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
