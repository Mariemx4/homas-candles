<?php

declare(strict_types=1);

namespace App\Controllers;

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

    public function __construct(private readonly array $productTypes)
    {
    }

    public function shop(array $query): array
    {
        $selectedCategory = (string) ($query['category'] ?? 'all');
        if ($selectedCategory !== 'all' && !in_array($selectedCategory, self::CATEGORY_SLUGS, true)) {
            $selectedCategory = 'all';
        }

        return $this->catalogueView($query) + [
            'selectedCategory' => $selectedCategory,
            'pageTitle' => "Shop | Hom'As Candles",
        ];
    }

    public function category(array $categoryPage, array $query): array
    {
        return $this->catalogueView($query) + [
            'categoryPage' => $categoryPage,
            'pageTitle' => $categoryPage['name'] . " | Hom'As Candles",
        ];
    }

    private function catalogueView(array $query): array
    {
        $selectedProductType = (string) ($query['type'] ?? 'all');
        if ($selectedProductType !== 'all' && !array_key_exists($selectedProductType, $this->productTypes)) {
            $selectedProductType = 'all';
        }

        $selectedSort = (string) ($query['sort'] ?? 'featured');
        if (!in_array($selectedSort, self::SORTS, true)) {
            $selectedSort = 'featured';
        }

        return [
            'selectedProductType' => $selectedProductType,
            'selectedSort' => $selectedSort,
            'products' => [],
            'catalogueError' => null,
            'catalogueProductTypes' => $this->productTypes,
        ];
    }
}
