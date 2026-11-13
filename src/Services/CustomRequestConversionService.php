<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\FormInput;
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
    public function convert(int $requestId, int $administratorId, array $input, ?string $expectedTotal = null): array
    {
        [$values, $errors] = $this->prepareQuotation($requestId, $input);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        if ($expectedTotal !== null && !hash_equals($this->totals($values)['total'], $expectedTotal)) {
            return ['success' => false,'errors' => ['quote' => 'Review the current total and deposit before creating the order.']];
        }

        try {
            return $this->transactions->run(function () use ($requestId, $administratorId, $values): array {
                $request = $this->requests->lockRequest($requestId);
                if ($request === null) {
                    throw new DomainException('The custom request could not be found.');
                }
                if (!empty($request['archived_at']) || (string) $request['request_status'] !== 'AGREED' || $request['converted_order_id'] !== null) {
                    throw new DomainException('Only an agreed, unconverted request can become an order.');
                }
                $minimumQuantity = $this->minimumQuantity($request);
                if (!isset($values['custom_lines']) && $values['quantity'] < $minimumQuantity) {
                    return ['success' => false, 'errors' => [
                        'quantity' => 'Enter an agreed quantity of at least ' . $minimumQuantity . ' for this request.',
                    ]];
                }
                $orderNumber = ($this->orderNumberGenerator)();
                if (!is_string($orderNumber) || preg_match('/^[A-Z0-9-]{8,30}$/', $orderNumber) !== 1) {
                    throw new \RuntimeException('The order number generator returned an invalid value.');
                }
                if (isset($values['custom_lines'])) {
                    $current = $this->requests->findRequestProducts($requestId);
                    if (array_map('intval', array_column($current, 'id')) !== array_column($values['custom_lines'], 'definition_id')) {
                        throw new DomainException('The custom product definitions changed. Reload this request.');
                    }
                }
                $componentLines = [];
                foreach ($values['custom_lines'] ?? [] as $line) {
                    foreach ($current as $definition) {
                        if ((int)$definition['id'] !== $line['definition_id']) {
                            continue;
                        }
                        foreach ($definition['components'] as $component) {
                            if ($component['product_id'] !== null) {
                                $product = (int)$component['product_id'];
                                $componentLines[$product] = ($componentLines[$product] ?? 0) + (int)$component['quantity'] * $line['quantity'];
                            }
                        }
                    }
                }
                if ($componentLines !== []) {
                    $active = $this->requests->componentOptions();
                    foreach (array_keys($componentLines) as $componentId) {
                        if (!isset($active[$componentId])) {
                            throw new DomainException('A referenced component is no longer active.');
                        }
                    }
                    $this->requests->assertComponentAvailability($componentLines);
                }
                $subtotalMillis = isset($values['custom_lines'])
                    ? array_sum(array_map(static fn (array $line): int => $line['unit_price_millis'] * $line['quantity'], $values['custom_lines']))
                    : $values['unit_price_millis'] * $values['quantity'];
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
                if (isset($values['custom_lines'])) {
                    $recipeCache = [];
                    foreach ($values['custom_lines'] as $line) {
                        $definition = current(array_filter($current, static fn (array $row): bool => (int)$row['id'] === $line['definition_id']));
                        $recipe = [];
                        $accessoryCost = 0;
                        $components = $definition['components'] ?? [];
                        // Only complete existing compositions can be auto-defined. Custom components require the established production editor.
                        if ($this->production !== null && $components !== [] && !array_filter($components, static fn (array $row): bool => $row['source'] === 'CUSTOM')) {
                            $materials = [];
                            $catalogue = $active ?? [];
                            foreach ($components as $component) {
                                $record = $catalogue[(int)$component['product_id']] ?? null;
                                if (!$record) {
                                    throw new DomainException('A referenced component is no longer active.');
                                }
                                if ($record['category_slug'] === CatalogueCategory::ACCESSORIES) {
                                    $accessoryCost = $component['unit_cost_snapshot'] === null ? null : ($accessoryCost === null ? null : $accessoryCost + (Money::parseMillis((string)$component['unit_cost_snapshot']) ?? 0) * (int)$component['quantity']);
                                    continue;
                                }
                                $key = $component['product_id'] . ':' . ($component['scent_id'] ?? '');
                                $recipeCache[$key] ??= $this->production->catalogueRecipe((int)$component['product_id'], $component['scent_id'] === null ? null : (int)$component['scent_id']);
                                foreach ($recipeCache[$key] as $material) {
                                    $materialId = (int)$material['material']['id'];
                                    $materials[$materialId] ??= ['material' => $material['material'],'millis' => 0];
                                    $materials[$materialId]['millis'] += (Money::parseMillis($material['quantity']) ?? 0) * (int)$component['quantity'];
                                }
                            }
                            foreach ($materials as $material) {
                                $recipe[] = ['material' => $material['material'],'quantity' => Money::formatMillis($material['millis'])];
                            }
                        }
                        $itemId = $this->requests->insertOrderItem(['order_id' => $orderId,'product_id' => null,'recipe_source' => 'CUSTOM',
                            'unit_cost' => $recipe !== [] && $accessoryCost !== null ? Money::formatMillis($this->production->recipeCostMillis($recipe) + $accessoryCost) : null,'product_name' => $line['product_name'],'quantity' => $line['quantity'],'unit_price' => $money($line['unit_price_millis']),
                            'line_total' => $money($line['unit_price_millis'] * $line['quantity']),'customization_notes' => $line['customization_notes']]);
                        if ($recipe !== []) {
                            $this->production->insertRecipe($itemId, $recipe);
                        }
                        $this->requests->linkRequestItem($line['definition_id'], $itemId);
                    }
                } else {
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
                    if ($recipe !== []) {
                        $this->production->insertRecipe($itemId, $recipe);
                    }
                }
                $this->requests->insertInitialOrderHistory($orderId, $administratorId);
                $this->requests->markConverted($requestId, $orderId);

                return ['success' => true, 'errors' => [], 'orderId' => $orderId, 'orderNumber' => $orderNumber];
            });
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['conversion' => $exception->getMessage()]];
        }
    }

    private function prepareQuotation(int $requestId, array $input): array
    {
        $definitions = $this->requests->findRequestProducts($requestId);
        if ($definitions === []) {
            [$values,$errors] = $this->validate($input);
            if (array_key_exists('custom_products', $input)) {
                $errors['custom_products'] = 'This legacy request uses its existing single-product quotation.';
            }
        } else {
            $raw = $input['custom_products'] ?? null;
            $errors = [];
            $lines = [];
            $values = [];
            if (!is_array($raw) || count($raw) !== count($definitions)) {
                return [[], ['custom_products' => 'Quote every new custom product exactly once.']];
            }
            foreach ($definitions as $index => $definition) {
                $line = array_values($raw)[$index] ?? [];
                if (!is_array($line) || (string)($line['definition_id'] ?? '') !== (string)$definition['id']) {
                    return [[], ['custom_products' => 'The custom product selection changed. Reload this request.']];
                }
                [$quoted,$lineErrors] = $this->validate(array_replace($input, array_intersect_key($line, array_flip(['product_name','quantity','unit_price','agreed_batch_price','customization_notes']))));
                if ($quoted['quantity'] < $this->minimumQuantity($definition)) {
                    $lineErrors['quantity'] = 'The agreed quantity is below this category minimum.';
                }
                foreach ($lineErrors as $key => $error) {
                    $errors['product_' . $index . '_' . $key] = 'Product ' . ($index + 1) . ': ' . $error;
                }
                $lines[] = $quoted + ['definition_id' => (int)$definition['id']];
                if ($index === 0) {
                    $values = $quoted;
                }
            }
            $values['custom_lines'] = $lines;
            if (array_sum(array_map(static fn (array $line): int => $line['quantity'] * $line['unit_price_millis'], $lines)) + ($values['delivery_fee_millis'] ?? 0) > 999999999999) {
                $errors['custom_products'] = 'The agreed order total exceeds the supported monetary limit.';
            }
        }
        return [$values, $errors];
    }

    private function totals(array $values): array
    {
        $subtotal = isset($values['custom_lines'])
            ? array_sum(array_map(static fn (array $line): int => $line['unit_price_millis'] * $line['quantity'], $values['custom_lines']))
            : $values['unit_price_millis'] * $values['quantity'];
        $total = OrderPricingService::total($subtotal, 0, 0, $values['delivery_fee_millis']);
        return ['subtotal' => Money::formatMillis($subtotal), 'delivery_fee' => Money::formatMillis($values['delivery_fee_millis']),
            'total' => Money::formatMillis($total), 'deposit' => Money::formatMillis(OrderPricingService::deposit($total))];
    }

    /**
     * Totals for the order an agreed request would become, without saving anything.
     *
     * @return array{success: bool, errors: array<string, string>, totals?: array<string, mixed>}
     */
    public function quote(int $requestId, array $input): array
    {
        $request = $this->requests->findSummary($requestId);
        if ($request === null || !empty($request['archived_at']) || $request['request_status'] !== 'AGREED' || $request['converted_order_id'] !== null) {
            return ['success' => false,'errors' => ['form' => 'Only an agreed, unconverted request can be quoted.']];
        }
        [$values, $errors] = $this->prepareQuotation($requestId, $input);
        if ($errors !== []) {
            return ['success' => false,'errors' => $errors];
        }
        if (!isset($values['custom_lines']) && $values['quantity'] < $this->minimumQuantity($request)) {
            return ['success' => false,'errors' => ['quantity' => 'The agreed quantity is below the minimum for this request.']];
        }
        return ['success' => true,'errors' => [],'totals' => $this->totals($values)];
    }

    /** Idempotent explicit action; never publishes products or changes the completed order. */
    public function createDrafts(int $requestId): array
    {
        try {
            $ids = $this->transactions->run(function () use ($requestId): array {
                $request = $this->requests->lockRequest($requestId);
                if ($request === null || $request['converted_order_id'] === null || !$this->requests->productionCompleted((int)$request['converted_order_id'])) {
                    throw new DomainException('Catalogue drafts become available after production reaches Prepared.');
                }
                $definitions = $this->requests->findRequestProducts($requestId);
                if ($definitions === []) {
                    throw new DomainException('This legacy request has no separate new-product definitions.');
                }
                $ids = [];
                foreach ($definitions as $definition) {
                    $ids[] = $this->requests->createCatalogueDraft($definition);
                }
                return $ids;
            });
            return ['success' => true,'errors' => [],'draftIds' => $ids];
        } catch (DomainException $error) {
            return ['success' => false,'errors' => ['drafts' => $error->getMessage()]];
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

        $custom = [];
        foreach ($request['custom_products'] ?? [] as $definition) {
            $composition = [];
            foreach ($definition['components'] ?? [] as $component) {
                $composition[] = $component['quantity'] . ' x ' . $component['product_name_snapshot'] . ' [' . $component['source'] . '] Color: ' . $component['color_value'] . '; Scent: ' . $component['scent_value'] . '; ' . $component['specifications'];
            }
            $custom[] = ['definition_id' => (string)$definition['id'],'product_name' => (string)$definition['working_name'],
                'quantity' => (string)$definition['requested_quantity'],'unit_price' => '','agreed_batch_price' => '',
                'customization_notes' => 'Color: ' . $definition['color_preferences'] . "\nScent: " . $definition['scent_preferences'] . "\n" . $definition['specifications'] . "\n" . implode("\n", $composition)];
        }
        return [
            'custom_products' => $custom,
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
        $categoryMinimum = ($request['category_slug'] ?? null) === CatalogueCategory::EVENT_FAVORS
            ? PricingService::EVENT_FAVOR_MINIMUM : 1;
        return max($categoryMinimum, (int) ($request['product_minimum_quantity'] ?? 1));
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function validate(array $input): array
    {
        $errors = [];
        $string = static fn (string $key): string => FormInput::trimmed($input, $key);
        $productName = $string('product_name');
        if ($productName === '' || Text::length($productName) > 180) {
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
            if ($method === 'DELIVERY' && ($address[$key] === '' || Text::length($address[$key]) > $maximum)) {
                $errors[$key] = 'This delivery address field is required and must fit the allowed length.';
            }
        }
        $postalCode = $string('postal_code');
        if ($method === 'DELIVERY' && preg_match('/^[0-9]{4}$/', $postalCode) !== 1) {
            $errors['postal_code'] = 'Enter a four-digit postal code.';
        }
        $customization = $string('customization_notes');
        $adminNotes = $string('administrator_notes');
        if (Text::length($customization) > 10000) {
            $errors['customization_notes'] = 'Customization notes must be 10,000 characters or fewer.';
        }
        if (Text::length($adminNotes) > 10000) {
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

}
