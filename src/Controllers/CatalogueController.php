<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\ProductRepository;
use App\Services\PublicAssetPathResolver;
use App\Services\Locale;
use App\Services\CatalogueCategory;

final class CatalogueController
{
    private const SORTS = ['featured', 'newest', 'price-low', 'price-high'];
    private const PER_PAGE = 12;


    public function __construct(
        private readonly ProductRepository $products,
        private readonly PublicAssetPathResolver $images
    ) {
    }

    public function shop(array $query): array
    {
        $categories = $this->categories();
        $selectedCategory = is_string($query['category'] ?? null) ? $query['category'] : 'all';
        $available = array_column($categories, null, 'slug');
        if ($selectedCategory !== 'all' && !isset($available[$selectedCategory])) {
            $selectedCategory = 'all';
        }

        $categorySlug = $selectedCategory === 'all' ? null : $selectedCategory;

        return $this->catalogueView($query, $categorySlug) + [
            'selectedCategory' => $selectedCategory,
            'catalogueCategories' => $categories,
            'pageTitle' => Locale::text('nav.shop') . " | Hom'As Candles",
        ];
    }

    public function category(string $categorySlug, array $query): array
    {
        // A category route owns its category. Query parameters cannot replace it.
        $categories = $this->categories();
        $categoryPage = [
            'slug' => $categorySlug,
            'name' => $categorySlug,
            'path' => 'category.php',
        ];
        $available = array_column($categories, null, 'slug');
        if (!isset($available[$categorySlug])) {
            http_response_code(404);
        } else {
            $categoryPage['name'] = $available[$categorySlug]['name'];
            $categoryPage['path'] = $available[$categorySlug]['path'];
        }

        foreach (['eyebrow', 'title', 'description', 'empty_title'] as $field) {
            $key = 'category.' . $categoryPage['slug'] . '.' . $field;
            $translated = Locale::text($key);
            $categoryPage[$field] = $translated !== $key
                ? $translated : Locale::text('category.generic.' . $field);
        }

        return $this->catalogueView($query, $categorySlug) + [
            'categoryPage' => $categoryPage,
            'catalogueCategories' => $categories,
            'pageTitle' => $categoryPage['name'] . " | Hom'As Candles",
        ];
    }

    private function catalogueView(array $query, ?string $categorySlug): array
    {
        $languageCode = Locale::current();
        $productTypes = $this->products->activeCatalogueTypes($languageCode);
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
        $totalProducts = $this->products->countActiveForCatalogue($categorySlug, $typeSlug, $selectedSearch, $languageCode);
        $totalPages = max(1, (int) ceil($totalProducts / self::PER_PAGE));
        $currentPage = min($requestedPage, $totalPages);
        $rows = $this->products->findActiveForCatalogue(
            $categorySlug,
            $typeSlug,
            $selectedSearch,
            $selectedSort,
            self::PER_PAGE,
            ($currentPage - 1) * self::PER_PAGE,
            $languageCode
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

    private function categories(): array
    {
        return CatalogueCategory::navigation($this->products->activeCatalogueCategories(Locale::current()));
    }
}
