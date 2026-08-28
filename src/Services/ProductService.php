<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ProductRepository;

final class ProductService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly PublicAssetPathResolver $images
    ) {
    }

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
            'scents' => $this->products->findActiveScents((int) $product['id'], $languageCode),
            'galleryImages' => $galleryImages,
        ];
    }
}
