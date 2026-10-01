<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Business service for Product Readiness; the one place that decides whether a product may be active, and keeps that true after every change.
 *
 * DATA FLOW: Catalogue, recipe, material and taxonomy services run their
 * mutations through applyChange() (or call settle() inside a transaction they
 * already own). After the change and a price recalculation, this service
 * re-evaluates readiness from AdminProductRepository and either rejects the
 * change (rolling the whole transaction back) or returns affected products
 * to draft in that same transaction.
 */

namespace App\Services;

use App\Database\TransactionManager;
use App\Repositories\AdminProductRepository;
use DomainException;

// WHAT: activation requirements, enforced on activation AND on every later
//       change that could break them.
// WHY:  readiness used to be checked only when a product was switched on.
//       Clearing a translation, removing the last colour or scent, a set
//       component, or a recipe line — or deactivating a component, colour or
//       scent another product relies on — could leave an "active" product the
//       shop could not actually sell. The policy is:
//         1. An edit to a product that would break that product's own
//            readiness is REJECTED; nothing is saved.
//         2. Products affected indirectly (sets whose component was
//            deactivated, products whose colour/scent was retired, products a
//            cost change left unpriced) are RETURNED TO DRAFT in the same
//            transaction, and the admin is told which ones.
//         3. Additions that need a follow-up step (a new colour still needs its
//            photo) return that product to draft rather than being refused,
//            because the photo can only be uploaded once the colour exists.
final class ProductReadinessService
{
    public const REJECT = 'reject';
    public const RETURN_TO_DRAFT = 'return_to_draft';

    private const LANGUAGES = ['en' => 'English', 'fr' => 'French', 'ar' => 'Arabic'];
    private const CANDLE = 'candles';
    private const SET = 'candle-sets';
    private const DIFFUSER = 'home-diffusers';
    private const ACCESSORY = 'accessories';

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly AdminProductRepository $products,
        private readonly PricingService $pricing,
        private readonly TransactionManager $transactions
    ) {
    }

    /**
     * FUNCTION PURPOSE: Run one catalogue change and enforce readiness for everything it affects, atomically.
     *
     * WHAT: change → reprice → check the directly edited products (reject or
     *       draft, per $mode) → return any other broken active products to draft.
     * WHY:  one transaction means a rejected change leaves no trace, and a
     *       dependant is never left active for even a moment after the change.
     *
     * @template T
     * @param callable(): T $change
     * @param list<int> $directProductIds Products the admin is editing right now.
     * @return array{result: T, notice: ?string}
     * @throws DomainException when a direct edit would break an active product (REJECT mode).
     */
    public function applyChange(callable $change, array $directProductIds = [], string $mode = self::REJECT): array
    {
        return $this->transactions->run(function () use ($change, $directProductIds, $mode): array {
            $result = $change();

            return ['result' => $result, 'notice' => $this->settle($directProductIds, $mode)];
        });
    }

    /**
     * FUNCTION PURPOSE: Enforce readiness after a change, inside the caller's open transaction.
     *
     * @param list<int> $directProductIds
     * @return string|null A message naming products returned to draft, or null.
     * @throws DomainException when a direct edit would break an active product (REJECT mode).
     */
    public function settle(array $directProductIds = [], string $mode = self::REJECT): ?string
    {
        // Prices first: a removed recipe line or component can make a product
        // unpriceable, and "price calculated" is itself a readiness requirement.
        $this->pricing->recalculateAll();

        foreach ($directProductIds as $productId) {
            if ($mode !== self::REJECT) {
                break;
            }
            $readiness = $this->evaluate($productId);
            if ($readiness !== null && $readiness['active'] && !$readiness['ready']) {
                throw new DomainException(
                    'This change would leave the active product incomplete (' . implode('; ', $readiness['missing'])
                    . '). Deactivate the product first, or make the change in a different order.'
                );
            }
        }

        return $this->returnUnreadyToDraft();
    }

    /**
     * FUNCTION PURPOSE: Evaluate every activation requirement for one product.
     *
     * @return array{items: list<array{key: string, label: string, met: bool}>, ready: bool, missing: list<string>, active: bool, name: string}|null
     */
    public function evaluate(int $productId): ?array
    {
        $product = $this->products->find($productId);
        if ($product === null) {
            return null;
        }
        $translations = $this->products->findTranslations($productId);
        $items = $this->items($product, $translations, $productId);
        $missing = array_values(array_map(
            static fn (array $item): string => $item['label'],
            // COLLECTION IDIOM: Keep only array items for which the callback returns true.
            array_filter($items, static fn (array $item): bool => !$item['met'])
        ));
        $englishName = trim((string) ($translations['en']['name'] ?? ''));

        return [
            'items' => $items,
            'ready' => $missing === [],
            'missing' => $missing,
            'active' => (int) $product['is_active'] === 1,
            'name' => $englishName !== '' ? $englishName : (string) ($product['slug'] ?? ('Product #' . $productId)),
        ];
    }

    /**
     * FUNCTION PURPOSE: Deactivate every active product that no longer meets its requirements.
     *
     * WHAT: checks all active products, not only the edited one.
     * WHY:  dependencies run in many directions (sets → components, diffusers →
     *       reference candles, products → colours/scents/materials). Checking
     *       every active product is simple and cannot miss one; the catalogue
     *       is small enough for this to be cheap.
     */
    private function returnUnreadyToDraft(): ?string
    {
        $returned = [];
        foreach ($this->products->findActiveProductIds() as $productId) {
            $readiness = $this->evaluate($productId);
            if ($readiness === null || $readiness['ready']) {
                continue;
            }
            $this->products->setActive($productId, false);
            $returned[] = $readiness['name'] . ' (' . implode('; ', $readiness['missing']) . ')';
        }

        return $returned === []
            ? null
            : 'Returned to draft because they no longer meet the activation requirements: ' . implode(', ', $returned) . '.';
    }

    /**
     * Build the individual readiness checks.
     *
     * @param array<string, mixed> $product
     * @param array<string, array{name: ?string, description: ?string}> $translations
     * @return list<array{key: string, label: string, met: bool}>
     */
    private function items(array $product, array $translations, int $productId): array
    {
        $category = (string) ($product['category_slug'] ?? '');
        $items = [];
        $items[] = ['key' => 'category', 'label' => 'A category is selected', 'met' => $product['category_id'] !== null];
        $items[] = ['key' => 'slug', 'label' => 'A URL slug is set', 'met' => is_string($product['slug'] ?? null) && $product['slug'] !== ''];
        // A legacy hand-typed price is not enough: activation needs a rule-calculated one.
        $items[] = [
            'key' => 'price',
            'label' => 'The selling price is calculated from the pricing rules',
            'met' => $product['selling_price'] !== null && ($product['price_calculated_at'] ?? null) !== null,
        ];
        $items[] = ['key' => 'image', 'label' => 'A main image is uploaded', 'met' => is_string($product['main_image_path'] ?? null) && $product['main_image_path'] !== ''];

        // Candle sets deliberately have no product type of their own.
        if ($category !== self::SET) {
            $items[] = ['key' => 'type', 'label' => 'A product type is selected', 'met' => $product['product_type_id'] !== null];
        }

        foreach (self::LANGUAGES as $languageCode => $label) {
            $translation = $translations[$languageCode] ?? ['name' => '', 'description' => ''];
            $items[] = [
                'key' => 'translation_' . $languageCode,
                'label' => $label . ' name and description are approved',
                'met' => trim((string) $translation['name']) !== '' && trim((string) $translation['description']) !== '',
            ];
        }

        // WHAT: only ACTIVE colours count, for both the photo and the "at least one" checks.
        // WHY:  the shop shows active colours only, so a product whose last
        //       colour was retired in the taxonomy screen cannot really be bought.
        $activeColors = array_values(array_filter(
            $this->products->findColors($productId),
            static fn (array $color): bool => (int) ($color['is_active'] ?? 1) === 1
        ));
        $items[] = [
            'key' => 'color_images',
            'label' => 'Every assigned active color has a photo',
            // COLLECTION IDIOM: Fold many array items into one accumulated result (for example, an all-items boolean).
            'met' => array_reduce(
                $activeColors,
                static fn (bool $carry, array $color): bool => $carry && is_string($color['image_path'] ?? null) && $color['image_path'] !== '',
                true
            ),
        ];

        if ($category === self::SET) {
            $items[] = ['key' => 'set_candles', 'label' => 'The set contains at least two candles', 'met' => $this->products->countCandleComponents($productId) >= 2];
            $items[] = ['key' => 'set_components_active', 'label' => 'Every set component is active', 'met' => $this->products->allComponentsActive($productId)];
        }
        if (in_array($category, [self::CANDLE, self::SET, self::DIFFUSER, self::ACCESSORY], true)) {
            $items[] = ['key' => 'colors', 'label' => 'At least one active color is assigned', 'met' => $activeColors !== []];
        }
        if (in_array($category, [self::CANDLE, self::SET, self::DIFFUSER], true)) {
            $items[] = ['key' => 'scents', 'label' => 'At least one active scent is assigned', 'met' => $this->products->countActiveAssignedScents($productId) >= 1];
        }
        if (in_array($category, [self::CANDLE, self::DIFFUSER], true)) {
            $items[] = ['key' => 'actual_recipe', 'label' => 'An actual material recipe is configured', 'met' => $this->products->hasRecipe($productId, 'ACTUAL')];
        }
        if ($category === self::DIFFUSER) {
            $items[] = [
                'key' => 'diffuser_pricing',
                'label' => 'Candle-equivalent pricing information is configured',
                'met' => $product['pricing_reference_product_id'] !== null || $this->products->hasRecipe($productId, 'CANDLE_EQUIVALENT'),
            ];
        }
        if (in_array($category, ['bouquets', 'event-favors'], true)) {
            $items[] = ['key' => 'candle_count', 'label' => 'A candle count is recorded', 'met' => (int) ($product['candle_count'] ?? 0) > 0];
            $items[] = ['key' => 'request_only', 'label' => 'Purchase mode is request only', 'met' => ($product['purchase_mode'] ?? null) === 'REQUEST_ONLY'];
        }

        return $items;
    }
}
