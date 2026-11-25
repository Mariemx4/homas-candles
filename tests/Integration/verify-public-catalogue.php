<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap/autoload.php';
require_once __DIR__ . '/../Support/TestCheck.php';

use App\Controllers\CatalogueController;
use App\Repositories\ProductRepository;
use App\Services\PublicAssetPathResolver;
use App\Services\Locale;

$connection = new PDO('sqlite::memory:');
$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$connection->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY, slug TEXT, is_active INTEGER)');
$connection->exec('CREATE TABLE category_translations (category_id INTEGER, language_code TEXT, name TEXT)');
$connection->exec('CREATE TABLE product_types (id INTEGER PRIMARY KEY, slug TEXT, is_active INTEGER)');
$connection->exec('CREATE TABLE product_type_translations (product_type_id INTEGER, language_code TEXT, name TEXT)');
$connection->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, category_id INTEGER, product_type_id INTEGER, slug TEXT, selling_price REAL, stock_quantity INTEGER, purchase_mode TEXT, is_made_to_order INTEGER, main_image_path TEXT, is_active INTEGER)');
$connection->exec('CREATE TABLE product_translations (product_id INTEGER, language_code TEXT, name TEXT, description TEXT)');
// The type filter also matches sets through their components (see tests/Integration/verify-candle-sets.php).
$connection->exec('CREATE TABLE set_components (set_product_id INTEGER, component_product_id INTEGER, quantity INTEGER)');
$connection->exec("INSERT INTO categories VALUES (1, 'candles', 1), (2, 'bouquets', 1), (3, 'accessories', 0)");
$connection->exec("INSERT INTO category_translations VALUES (1, 'en', 'Candles'), (2, 'en', 'Bouquets'), (3, 'en', 'Accessories')");
$connection->exec("INSERT INTO product_types VALUES (1, 'flowers', 1), (2, 'retired', 0)");
$connection->exec("INSERT INTO product_type_translations VALUES (1, 'en', 'Flowers'), (2, 'en', 'Retired')");

$product = $connection->prepare('INSERT INTO products VALUES (?, ?, ?, ?, ?, NULL, ?, 1, ?, ?)');
$translation = $connection->prepare("INSERT INTO product_translations VALUES (?, 'en', ?, ?)");
for ($id = 1; $id <= 13; $id++) {
    $product->execute([$id, 1, 1, sprintf('candle-%02d', $id), $id, 'CART', 'assets/products/.gitkeep', 1]);
    $translation->execute([$id, sprintf('Candle %02d', $id), 'Handmade candle']);
}
$product->execute([14, 2, 1, 'special-bloom', 100, 'CART', 'assets/products/.gitkeep', 1]);
$translation->execute([14, 'Special Bloom', 'Custom bouquet']);
$product->execute([15, 1, 1, 'hidden-candle', 2, 'CART', 'assets/products/.gitkeep', 0]);
$translation->execute([15, 'Hidden Candle', 'Inactive']);
$product->execute([16, 3, 1, 'hidden-category', 2, 'CART', 'assets/products/.gitkeep', 1]);
$translation->execute([16, 'Hidden Category', 'Inactive category']);

$controller = new CatalogueController(
    new ProductRepository($connection),
    new PublicAssetPathResolver(PUBLIC_PATH, PRODUCT_IMAGE_PATH)
);
$check = TestCheck::quiet();

$shop = $controller->shop([]);
$check($shop['totalProducts'] === 14 && count($shop['products']) === 12 && $shop['totalPages'] === 2, 'Active products are counted and paginated.');
$check($shop['products'][0]['slug'] === 'candle-01', 'Featured order is stable.');
$pageTwo = $controller->shop(['page' => '2']);
$check($pageTwo['currentPage'] === 2 && count($pageTwo['products']) === 2, 'Second page contains the remaining products.');
$check($controller->shop(['page' => '999'])['currentPage'] === 2, 'Out-of-range pages clamp to the last page.');
$check($controller->shop(['sort' => 'newest'])['products'][0]['slug'] === 'special-bloom', 'Newest order uses descending IDs.');
$check($controller->shop(['sort' => 'price-low'])['products'][0]['slug'] === 'candle-01', 'Ascending price order is applied in SQL.');
$check($controller->shop(['sort' => 'price-high', 'page' => '2'])['products'][1]['purchase_mode'] === 'REQUEST_ONLY', 'Request-only products sort after priced products.');
$check($controller->shop(['category' => 'bouquets'])['totalProducts'] === 1, 'The bouquet filter uses the database category slug.');
$bouquetNavigation = $controller->shop([])['catalogueCategories'];
$check(
    $bouquetNavigation[1]['slug'] === 'bouquets'
    && $bouquetNavigation[1]['path'] === 'category.php?slug=bouquets',
    'Built-in categories use the same route and slug as admin-created categories.'
);
$connection->exec("INSERT INTO categories VALUES (4, 'gift-boxes', 1)");
$connection->exec("INSERT INTO category_translations VALUES (4, 'en', 'Gift Boxes')");
$customCategories = $controller->shop([])['catalogueCategories'];
$check(
    $customCategories[2]['slug'] === 'gift-boxes'
    && $customCategories[2]['path'] === 'category.php?slug=gift-boxes',
    'New active admin categories appear in storefront taxonomy with a generic route.'
);
$connection->exec("UPDATE categories SET is_active = 0 WHERE id = 4");
$check(
    count($controller->shop([])['catalogueCategories']) === 2,
    'Retired admin categories leave storefront taxonomy.'
);
$check($controller->shop(['query' => 'Special Bloom'])['products'][0]['slug'] === 'special-bloom', 'Search queries product names.');
$check($controller->shop(['query' => '%'])['totalProducts'] === 0, 'Search wildcards are treated literally.');
$check($controller->shop(['type' => 'flowers'])['totalProducts'] === 14, 'Product type filters query active products.');
$check($controller->shop(['type' => 'retired'])['selectedProductType'] === 'all', 'Inactive product types cannot be selected.');
$check($controller->shop(['category' => ['candles'], 'sort' => ['newest'], 'page' => ['2']])['currentPage'] === 1, 'Malformed filters and pages fall back safely.');

$category = $controller->category('bouquets', ['category' => 'candles']);
$check($category['totalProducts'] === 1 && $category['products'][0]['purchase_mode'] === 'REQUEST_ONLY', 'Category pages retain their own category and request-only product.');
$check(
    $category['categoryPage']['path'] === 'category.php?slug=bouquets'
    && $category['categoryPage']['title'] === 'Bouquets where flowers, fragrance, and light meet.',
    'The shared route keeps the collection title and canonical path.'
);
$custom = $controller->category('gift-boxes', []);
$check(http_response_code() === 404, 'An inactive collection cannot be opened directly.');
$connection->exec('UPDATE categories SET is_active = 1 WHERE id = 4');
$custom = $controller->category('gift-boxes', []);
$check(
    $custom['categoryPage']['name'] === 'Gift Boxes'
    && $custom['categoryPage']['path'] === 'category.php?slug=gift-boxes'
    && $custom['categoryPage']['title'] === Locale::text('category.generic.title'),
    'Admin-created collections use the same route with generic collection copy.'
);
http_response_code(200);

define('BASE_URL', '/');
$filtered = $controller->shop([
    'category' => 'candles', 'type' => 'flowers', 'query' => 'Candle', 'sort' => 'newest',
]);
extract($filtered);
$cataloguePagePath = 'shop.php';
$catalogueView = ['class_prefix' => 'shop', 'loading_title' => 'Loading', 'error_title' => 'Error'];
ob_start();
require VIEW_PATH . '/Components/catalogue-results.php';
$html = (string) ob_get_clean();
$check(
    str_contains($html, 'category=candles&amp;type=flowers&amp;query=Candle&amp;page=2')
    || (str_contains($html, 'category=candles') && str_contains($html, 'type=flowers')
        && str_contains($html, 'query=Candle') && str_contains($html, 'page=2')),
    'Pagination links preserve the active filters and search.'
);

$connection->exec("INSERT INTO category_translations VALUES (1, 'fr', 'Bougies')");
$connection->exec("INSERT INTO product_type_translations VALUES (1, 'fr', 'Fleurs')");
$connection->exec("INSERT INTO product_translations VALUES (1, 'fr', 'Bougie florale', 'Parfum doux')");
$repository = new ProductRepository($connection);
$check($repository->activeCatalogueTypes('fr')['flowers'] === 'Fleurs', 'Product types use the selected database language.');
$french = $repository->findActiveForCatalogue('candles', null, 'Bougie florale', 'featured', 12, 0, 'fr');
$check(count($french) === 1 && $french[0]['name'] === 'Bougie florale' && $french[0]['category_name'] === 'Bougies', 'Catalogue search and labels use French translations.');
$fallback = $repository->findActiveForCatalogue('candles', null, 'Candle 02', 'featured', 12, 0, 'fr');
$check(count($fallback) === 1 && $fallback[0]['name'] === 'Candle 02', 'Missing database translations fall back to English.');
$check(Locale::resolve(['lang' => 'ar'], ['homas_language' => 'fr']) === 'ar', 'Explicit locale wins over the preference cookie.');
$check(Locale::resolve([], ['homas_language' => 'fr']) === 'fr', 'Preference cookie survives navigation.');
$check(Locale::resolve([], [], 'ar-TN,fr;q=0.9') === 'ar', 'Browser language is used when no preference exists.');
$check(Locale::text('shop.apply', 'ar') === 'تطبيق', 'Arabic translation dictionary is loaded.');

echo "Public catalogue checks passed.\n";
