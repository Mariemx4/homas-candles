<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CustomRequestRepository;
use DomainException;

/**
 * Validates the "requested products" part of a custom request brief.
 *
 * Owner: custom requests. Used by CustomRequestService when an administrator
 * records a new request and when a working brief is corrected, so both paths
 * accept exactly the same definitions.
 *
 * A request lists 1–50 requested products. Each has a working name, category,
 * product type, quantity, optional color/scent choice and specifications, and
 * up to 50 components. A component is one of:
 *
 * - PRODUCT: an active catalogue product that may be purchased alone;
 * - ITEM:    an active catalogue record used as a part (never a candle set);
 * - CUSTOM:  a named component that does not exist in the catalogue yet.
 *
 * Catalogue components may only use the colors/scents configured for that
 * product; custom components may use any active option or a custom value.
 * Catalogue cost is copied as `unit_cost_snapshot` so later price changes do
 * not alter what was discussed.
 *
 * Reads options through the repository; never writes. Errors are returned as
 * one `custom_products` message naming the product number to fix.
 */
final class CustomRequestDefinitionValidator
{
    public function __construct(private readonly CustomRequestRepository $requests)
    {
    }

    /**
     * Read one color/scent choice: an existing option ID, an explicit custom
     * text, or nothing. Standard IDs and custom values are kept separate so a
     * typed "Rose" is never mistaken for the catalogue color named Rose.
     *
     * Older forms posted free text in `<kind>_preferences`; that is still
     * accepted as a custom value where custom values are allowed.
     *
     * @param array<int, string> $allowed Option names keyed by ID.
     * @return array{0: 'EXISTING'|'CUSTOM'|'NONE', 1: ?int, 2: string} Mode, option ID, display value.
     */
    private function requestedOption(array $row, string $kind, array $allowed, bool $customAllowed, bool $required = false): array
    {
        if (!array_key_exists($kind . '_choice', $row)) {
            $legacy = is_string($row[$kind . '_preferences'] ?? null) ? trim($row[$kind . '_preferences']) : '';
            if (!$required && $customAllowed && $legacy !== '') {
                return ['CUSTOM',null,$legacy];
            }
        }
        $choice = is_string($row[$kind . '_choice'] ?? null) ? $row[$kind . '_choice'] : '';
        if ($choice === '__custom__') {
            $value = is_string($row[$kind . '_custom'] ?? null) ? trim($row[$kind . '_custom']) : '';
            if (!$customAllowed || $value === '' || Text::length($value) > 500) {
                throw new DomainException('Enter an explicit custom ' . $kind . ' value of up to 500 characters.');
            }
            return ['CUSTOM',null,$value];
        }
        if ($choice === '') {
            if ($required && !$customAllowed && $allowed !== []) {
                throw new DomainException('Select a configured ' . $kind . ' for this component.');
            }
            return ['NONE',null,''];
        }
        if (!ctype_digit($choice) || !isset($allowed[(int)$choice])) {
            throw new DomainException('Select an allowed ' . $kind . '.');
        }
        return ['EXISTING',(int)$choice,(string)$allowed[(int)$choice]];
    }

    /**
     * Validate a submitted list of requested products and their components.
     *
     * @return array{success: true, errors: array{}, definitions: list<array<string, mixed>>}
     *       | array{success: false, errors: array{custom_products: string}}
     */
    public function validate(mixed $raw): array
    {
        $definitions = [];
        if (!is_array($raw) || count($raw) < 1 || count($raw) > 50) {
            return ['success' => false,'errors' => ['custom_products' => 'Add between one and 50 requested products.']];
        }
        $categories = array_map('strval', array_column($this->requests->findPublicCategories(), 'id'));
        $types = array_map('strval', array_column($this->requests->productTypes(), 'id'));
        $catalogue = $this->requests->componentOptions();
        $colors = array_column($this->requests->optionChoices('colors'), 'name', 'id');
        $scents = array_column($this->requests->optionChoices('scents'), 'name', 'id');
        foreach (array_values($raw) as $index => $row) {
            if (!is_array($row)) {
                return ['success' => false,'errors' => ['custom_products' => 'Each custom product needs its own definition.']];
            }
            $read = static fn (string $key): string => is_string($row[$key] ?? null) ? trim($row[$key]) : '';
            $definition = [];
            foreach (['working_name','category_id','product_type_id','requested_quantity','color_preferences','scent_preferences','specifications'] as $key) {
                $definition[$key] = $read($key);
            }
            $quantity = filter_var($definition['requested_quantity'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1,'max_range' => 1000000]]);
            if ($definition['working_name'] === '' || Text::length($definition['working_name']) > 180
                || !in_array($definition['category_id'], $categories, true)
                || !in_array($definition['product_type_id'], $types, true) || $quantity === false
                || Text::length($definition['color_preferences']) > 500 || Text::length($definition['scent_preferences']) > 500
                || Text::length($definition['specifications']) > 10000) {
                return ['success' => false,'errors' => ['custom_products' => 'Product ' . ($index + 1) . ': enter a working name, valid category/type, quantity, and specifications within the allowed lengths.']];
            }
            try {
                foreach (['color' => $colors,'scent' => $scents] as $kind => $choices) {
                    [$mode,$optionId,$optionName] = $this->requestedOption($row, $kind, $choices, true);
                    $definition[$kind . '_mode'] = $mode;
                    $definition[$kind . '_id'] = $optionId;
                    $definition[$kind . '_preferences'] = $optionName;
                }
                $definition['components'] = [];
                $components = $row['components'] ?? [];
                if (!is_array($components) || count($components) > 50) {
                    throw new DomainException('Use at most 50 components per requested product.');
                }
                foreach ($components as $component) {
                    if (!is_array($component)) {
                        throw new DomainException('A component definition is invalid.');
                    }
                    $source = $component['source'] ?? '';
                    if (!in_array($source, ['PRODUCT','ITEM','CUSTOM'], true)) {
                        throw new DomainException('Choose an existing Product, Item, or a custom component.');
                    }
                    $qty = filter_var($component['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1,'max_range' => 1000000]]);
                    if ($qty === false) {
                        throw new DomainException('Every component needs a valid whole quantity.');
                    }
                    $id = $component['product_id'] ?? '';
                    $record = $catalogue[(int)$id] ?? null;
                    if ($source !== 'CUSTOM' && (!is_string($id) || !ctype_digit($id) || !$record
                        || ($source === 'PRODUCT' && !$record['can_purchase_alone'])
                        || ($source === 'ITEM' && $record['category_slug'] === CatalogueCategory::SETS))) {
                        throw new DomainException('Select a valid active catalogue record for this component source.');
                    }
                    $name = $source === 'CUSTOM' ? trim(is_string($component['custom_name'] ?? null) ? $component['custom_name'] : '') : $record['name'];
                    $notes = trim(is_string($component['specifications'] ?? null) ? $component['specifications'] : '');
                    if ($name === '' || Text::length($name) > 180 || Text::length($notes) > 10000) {
                        throw new DomainException('Enter a component name and specifications within the allowed lengths.');
                    }
                    $child = ['source' => $source,'product_id' => $source === 'CUSTOM' ? null : (int)$id,'quantity' => $qty,'product_name_snapshot' => $name,'unit_cost_snapshot' => $source === 'CUSTOM' ? null : $record['actual_cost'],'specifications' => $notes];
                    foreach (['color' => $colors,'scent' => $scents] as $kind => $choices) {
                        $allowed = $source === 'CUSTOM' ? $choices : $record[$kind === 'color' ? 'colors' : 'scents'];
                        [$mode,$optionId,$optionName] = $this->requestedOption($component, $kind, $allowed, $source === 'CUSTOM', true);
                        $child[$kind . '_mode'] = $mode;
                        $child[$kind . '_id'] = $optionId;
                        $child[$kind . '_value'] = $optionName;
                    }
                    $definition['components'][] = $child;
                }
            } catch (DomainException $error) {
                return ['success' => false,'errors' => ['custom_products' => 'Requested product ' . ($index + 1) . ': ' . $error->getMessage()]];
            }
            $definitions[] = $definition;
        }
        return ['success' => true,'errors' => [],'definitions' => $definitions];
    }
}
