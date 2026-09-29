<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Application controller for Catalogue Controller; it coordinates a use case for the HTTP-facing layer.
 *
 * DATA FLOW: A public entry point calls this controller with request-ready input. The controller delegates rules to services and returns data for a redirect, response, or view.
 */

namespace App\Controllers;

use App\Repositories\ProductRepository;
use App\Services\PublicAssetPathResolver;

final class CatalogueController
{
    private const CATEGORY_SLUGS = [
        'candles',
        'candle-sets',
        'candle-bouquets',
        'event-favors',
        'home-diffusers',
        'accessories',
    ];

    private const SORTS = ['featured', 'newest', 'price-low', 'price-high'];
    private const PER_PAGE = 12;

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly ProductRepository $products,
        private readonly PublicAssetPathResolver $images
    ) {
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `shop` (shop) and return its result.
     */
    public function shop(array $query): array
    {
        $selectedCategory = is_string($query['category'] ?? null) ? $query['category'] : 'all';
        if ($selectedCategory !== 'all' && !in_array($selectedCategory, self::CATEGORY_SLUGS, true)) {
            $selectedCategory = 'all';
        }

        $categorySlug = $selectedCategory === 'all' ? null : $this->databaseCategorySlug($selectedCategory);

        return $this->catalogueView($query, $categorySlug) + [
            'selectedCategory' => $selectedCategory,
            'pageTitle' => "Shop | Hom'As Candles",
        ];
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `category` (category) and return its result.
     */
    public function category(array $categoryPage, array $query): array
    {
        // A category route owns its category. Query parameters cannot replace it.
        $categorySlug = $this->databaseCategorySlug((string) $categoryPage['slug']);

        return $this->catalogueView($query, $categorySlug) + [
            'categoryPage' => $categoryPage,
            'pageTitle' => $categoryPage['name'] . " | Hom'As Candles",
        ];
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `catalogueView` (catalogue view) and return its result.
     */
    private function catalogueView(array $query, ?string $categorySlug): array
    {
        $productTypes = $this->products->activeCatalogueTypes();
        $selectedProductType = is_string($query['type'] ?? null) ? $query['type'] : 'all';
        if ($selectedProductType !== 'all' && !array_key_exists($selectedProductType, $productTypes)) {
            $selectedProductType = 'all';
        }

        $selectedSort = is_string($query['sort'] ?? null) ? $query['sort'] : 'featured';
        if (!in_array($selectedSort, self::SORTS, true)) {
            $selectedSort = 'featured';
        }

        $selectedSearch = is_string($query['query'] ?? null) ? trim($query['query']) : '';
        if (strlen($selectedSearch) > 100) {
            $selectedSearch = '';
        }

        $requestedPage = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $requestedPage = $requestedPage === false ? 1 : $requestedPage;
        $typeSlug = $selectedProductType === 'all' ? null : $selectedProductType;
        $totalProducts = $this->products->countActiveForCatalogue($categorySlug, $typeSlug, $selectedSearch);
        $totalPages = max(1, (int) ceil($totalProducts / self::PER_PAGE));
        $currentPage = min($requestedPage, $totalPages);
        $rows = $this->products->findActiveForCatalogue(
            $categorySlug,
            $typeSlug,
            $selectedSearch,
            $selectedSort,
            self::PER_PAGE,
            ($currentPage - 1) * self::PER_PAGE
        );
        $products = [];
        foreach ($rows as $product) {
            $image = $this->images->resolve($product['main_image_path']);
            if ($image === null) {
                continue;
            }
            $product['main_image_path'] = $image;
            $products[] = $product;
        }

        return [
            'selectedProductType' => $selectedProductType,
            'selectedSort' => $selectedSort,
            'selectedSearch' => $selectedSearch,
            'products' => $products,
            'totalProducts' => $totalProducts,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'catalogueError' => null,
            'catalogueProductTypes' => $productTypes,
        ];
    }

    private function databaseCategorySlug(string $publicSlug): string
    {
        return $publicSlug === 'candle-bouquets' ? 'bouquets' : $publicSlug;
    }
}
