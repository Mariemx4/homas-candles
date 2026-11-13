<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\FormInput;
use App\Repositories\AdminProductRepository;

/**
 * Validates the admin "core settings" form of a product.
 *
 * Owner: catalogue administration. Called only by AdminProductService::saveCore().
 *
 * Input:  the raw POST array (untrusted strings) and the product ID being
 *         edited, or null for a new product.
 * Output: `[clean database values, field errors]`. All errors are collected in
 *         one pass so the administrator can fix the whole form at once.
 *
 * Category-driven business rules live here because they decide what a valid
 * product is, whatever screen submits it:
 *
 * - candle sets have no product type; every other category requires one;
 * - only accessories track a stock quantity, and they cannot be bought alone;
 * - bouquets and event favors are always REQUEST_ONLY (a crafted POST cannot
 *   expose them in the cart), and event favors need a minimum quantity of 10;
 * - only home diffusers may reference a candle for pricing;
 * - candles, bouquets, event favors and diffusers are always made to order;
 * - a submitted selling_price is ignored: PricingService calculates prices.
 *
 * Inactive categories/types are accepted only when the product already uses
 * them, so editing an older product does not force an unrelated change.
 *
 * This class reads lookups through the repository but never writes.
 */
final class ProductCoreValidator
{
    public function __construct(private readonly AdminProductRepository $products)
    {
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public function validate(array $input, ?int $productId): array
    {
        $errors = [];
        // Safely obtain a trimmed string even when POST contains a missing key
        // or an unexpected array value.
        $string = static fn (string $key): string => FormInput::trimmed($input, $key);

        // Category selection controls several later conditional rules.
        $existingProduct = $productId === null ? null : $this->products->find($productId);
        $categoryId = filter_var($string('category_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $category = null;
        if ($categoryId === false) {
            $errors['category_id'] = 'Select a category.';
        } else {
            $category = $this->products->findCategory($categoryId);
            if ($category === null || ((int)$category['is_active'] !== 1 && (string)($existingProduct['category_id'] ?? '') !== (string)$categoryId)) {
                $errors['category_id'] = 'Select a valid category.';
            }
        }
        $categorySlug = $category['slug'] ?? null;
        $isSet = $categorySlug === CatalogueCategory::SETS;
        $isAccessory = $categorySlug === CatalogueCategory::ACCESSORIES;
        $isDiffuser = $categorySlug === CatalogueCategory::DIFFUSERS;
        $isBouquet = $categorySlug === CatalogueCategory::BOUQUETS;
        $isEventFavor = $categorySlug === CatalogueCategory::EVENT_FAVORS;

        // A slug becomes the readable URL segment: groups of lowercase
        // letters/numbers separated by single hyphens.
        $slugRaw = strtolower($string('slug'));
        $slug = null;
        if ($slugRaw !== '') {
            if (strlen($slugRaw) > 180 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slugRaw) !== 1) {
                $errors['slug'] = 'Enter a slug using lowercase letters, numbers, and single hyphens only.';
            } elseif ($this->products->slugExists($slugRaw, $productId)) {
                $errors['slug'] = 'This slug is already used by another product.';
            } else {
                $slug = $slugRaw;
            }
        }

        $productTypeRaw = $string('product_type_id');
        $productTypeId = null;
        if ($productTypeRaw !== '') {
            $productTypeId = filter_var($productTypeRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($productTypeId === false || (!$this->products->activeProductTypeExists($productTypeId) && (string)($existingProduct['product_type_id'] ?? '') !== (string)$productTypeId)) {
                $errors['product_type_id'] = 'Select a valid, active product type.';
                $productTypeId = null;
            }
        } elseif (!$isSet) {
            $errors['product_type_id'] = 'A product type is required for this category.';
        }
        if ($isSet) {
            $productTypeId = null;
        }

        $stockRaw = $string('stock_quantity');
        $stockQuantity = null;
        if ($isAccessory) {
            $stockQuantity = filter_var($stockRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($stockQuantity === false) {
                $errors['stock_quantity'] = 'Accessories require a stock quantity of zero or more.';
                $stockQuantity = null;
            }
        } elseif ($stockRaw !== '') {
            $errors['stock_quantity'] = 'Stock quantity only applies to accessories.';
        }

        $candleCountRaw = $string('candle_count');
        $candleCount = null;
        if ($candleCountRaw !== '') {
            $candleCount = filter_var($candleCountRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($candleCount === false) {
                $errors['candle_count'] = 'Enter a whole candle count greater than zero, or leave it blank.';
                $candleCount = null;
            }
        }

        $minimumQuantity = filter_var($string('minimum_quantity'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000, 'default' => 1]]);
        if ($minimumQuantity === false) {
            $errors['minimum_quantity'] = 'Enter a whole minimum quantity of at least 1.';
            $minimumQuantity = 1;
        }
        if ($isEventFavor && $minimumQuantity < 10) {
            $errors['minimum_quantity'] = 'Event favors require a minimum order quantity of at least 10.';
        }

        $purchaseMode = strtoupper($string('purchase_mode'));
        if ($purchaseMode === '') {
            $purchaseMode = 'CART';
        } elseif (!in_array($purchaseMode, AdminProductService::PURCHASE_MODES, true)) {
            $errors['purchase_mode'] = 'Select a valid purchase mode.';
            $purchaseMode = 'CART';
        }
        if ($isBouquet || $isEventFavor) {
            $purchaseMode = 'REQUEST_ONLY';
        }

        $referenceRaw = $string('pricing_reference_product_id');
        $referenceId = null;
        if ($referenceRaw !== '' && $isDiffuser) {
            $referenceId = filter_var($referenceRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $referenceProduct = $referenceId === false ? null : $this->products->find($referenceId);
            if ($referenceId === false || $referenceId === $productId || $referenceProduct === null
                || $referenceProduct['category_slug'] !== CatalogueCategory::CANDLES
            ) {
                $errors['pricing_reference_product_id'] = 'Select a valid candle product to reference, or leave it blank.';
                $referenceId = null;
            }
        } elseif ($referenceRaw !== '') {
            $errors['pricing_reference_product_id'] = 'A pricing reference only applies to home diffusers.';
        }

        // Checkboxes are submitted as "1" only when checked; the columns store 1/0.
        $isMadeToOrder = ($input['is_made_to_order'] ?? '') === '1';
        $canPurchaseAlone = ($input['can_purchase_alone'] ?? '') === '1';
        if ($isAccessory) {
            $canPurchaseAlone = false;
        }
        if (in_array($categorySlug, [CatalogueCategory::CANDLES, CatalogueCategory::BOUQUETS, CatalogueCategory::EVENT_FAVORS, CatalogueCategory::DIFFUSERS], true)) {
            $isMadeToOrder = true;
        }

        $core = [
            'category_id' => $categoryId === false ? null : $categoryId,
            'product_type_id' => $productTypeId,
            'pricing_reference_product_id' => $referenceId,
            'slug' => $slug,
            'stock_quantity' => $stockQuantity,
            'candle_count' => $candleCount,
            'minimum_quantity' => $minimumQuantity,
            'purchase_mode' => $purchaseMode,
            'is_made_to_order' => $isMadeToOrder ? 1 : 0,
            'can_purchase_alone' => $canPurchaseAlone ? 1 : 0,
        ];

        return [$core, $errors];
    }
}
