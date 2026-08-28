<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ProductService;
use PDOException;
use RuntimeException;

final class ProductController
{
    public function __construct(private readonly ProductService $products)
    {
    }

    public function show(string $untrustedSlug): array
    {
        $slug = trim($untrustedSlug);
        $view = [
            'product' => null,
            'colors' => [],
            'scents' => [],
            'galleryImages' => [],
            'productLoadError' => null,
        ];

        if ($slug === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            return $this->withPageTitle($view);
        }

        try {
            $productData = $this->products->findForCatalogue($slug);
            if ($productData !== null) {
                $view = array_replace($view, $productData);
            }
        } catch (RuntimeException | PDOException) {
            $view['productLoadError'] = 'The product service is temporarily unavailable. Please try again in a moment.';
        }

        return $this->withPageTitle($view);
    }

    private function withPageTitle(array $view): array
    {
        $view['pageTitle'] = $view['product'] !== null
            ? $view['product']['name'] . " | Hom'As Candles"
            : "Product | Hom'As Candles";

        return $view;
    }
}
