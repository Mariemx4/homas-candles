<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Business service for Product Service; it contains application rules and coordinates related persistence work.
 *
 * DATA FLOW: Controllers call this layer. It validates and normalizes domain input, calls repositories or storage collaborators, and returns structured results without rendering HTML.
 */

namespace App\Services;

use App\Repositories\ProductRepository;

final class ProductService
{
    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly ProductRepository $products,
        private readonly PublicAssetPathResolver $images,
        // Null only in lightweight SQLite harnesses without the stock tables;
        // app/Bootstrap/product.php always supplies it.
        private readonly ?ScentAvailabilityService $scentAvailability = null
    ) {
    }

    /**
     * FUNCTION PURPOSE: Fetch for catalogue and return the matching data when it exists.
     */
    public function findForCatalogue(string $slug, string $languageCode = 'en'): ?array
    {
        $product = $this->products->findActiveBySlug($slug, $languageCode);
        if ($product === null) {
            return null;
        }

        $mainImage = $this->images->resolve($product['main_image_path']);
        if ($mainImage === null) {
            return null;
        }

        $product['main_image_path'] = $mainImage;
        $colors = $this->products->findActiveColors((int) $product['id'], $languageCode);
        foreach ($colors as &$color) {
            $color['image_path'] = $this->images->resolve($color['image_path']);
        }
        unset($color);

        $galleryImages = [$mainImage];
        foreach ($colors as $color) {
            if ($color['image_path'] !== null && !in_array($color['image_path'], $galleryImages, true)) {
                $galleryImages[] = $color['image_path'];
            }
        }

        return [
            'product' => $product,
            'colors' => $colors,
            'scents' => $this->scents($product, $languageCode),
            'galleryImages' => $galleryImages,
            // Only candle sets have components; every other product gets an empty list.
            'components' => $product['category_slug'] === 'candle-sets'
                ? $this->setComponents((int) $product['id'], $languageCode)
                : [],
        ];
    }

    /**
     * FUNCTION PURPOSE: Fetch the product's active scents, each marked available or not.
     *
     * WHAT: `is_available` is false when the scent's fragrance material cannot
     *       supply the product's minimum quantity (ScentAvailabilityService).
     * WHY:  a scent used to be offered whenever it was active, even with its
     *       fragrance oil used up. Unavailable scents stay listed (shown
     *       disabled) so customers see the range, but the cart refuses them.
     *
     * @return list<array<string, mixed>>
     */
    private function scents(array $product, string $languageCode): array
    {
        $scents = $this->products->findActiveScents((int) $product['id'], $languageCode);
        $availability = $this->scentAvailability === null || $scents === []
            ? []
            : $this->scentAvailability->availability(
                (int) $product['id'],
                (int) ($product['minimum_quantity'] ?? 1),
                array_map(static fn (array $scent): int => (int) $scent['id'], $scents)
            );
        foreach ($scents as &$scent) {
            $scent['is_available'] = $availability[(int) $scent['id']] ?? true;
        }
        unset($scent);

        return $scents;
    }

    /**
     * FUNCTION PURPOSE: Build the "What's in this set" rows for a candle set's product page.
     *
     * WHAT: each row has the component's name, category/type, quantity, unit
     *       and line price, a safe image path, and whether it can be linked.
     * WHY:  documented rule — a set displays its component products,
     *       quantities, prices, and product-page links. Money is handled in
     *       integer thousandths so the line totals and the "bought separately"
     *       total never pick up float rounding errors.
     *
     * @return list<array<string, mixed>>
     */
    private function setComponents(int $setProductId, string $languageCode): array
    {
        $components = [];
        foreach ($this->products->findSetComponents($setProductId, $languageCode) as $row) {
            $isAvailable = (int) $row['is_available'] === 1;
            // A price is only shown when the piece can really be bought at that price.
            $unitMillis = $isAvailable && $row['purchase_mode'] === 'CART' && is_numeric($row['selling_price'])
                ? (int) round(((float) $row['selling_price']) * 1000)
                : null;
            $quantity = max(1, (int) $row['quantity']);
            $components[] = [
                'id' => (int) $row['id'],
                'slug' => $isAvailable ? (string) $row['slug'] : null,
                'name' => (string) $row['name'],
                'category_name' => (string) $row['category_name'],
                'type_name' => $row['type_name'] === null ? null : (string) $row['type_name'],
                'quantity' => $quantity,
                'unit_price_millis' => $unitMillis,
                'line_total_millis' => $unitMillis === null ? null : $unitMillis * $quantity,
                // Same image boundary as the rest of the catalogue; null hides the thumbnail.
                'image' => $this->images->resolve($row['main_image_path']),
                'is_available' => $isAvailable,
            ];
        }

        return $components;
    }
}
