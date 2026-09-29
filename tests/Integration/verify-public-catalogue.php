<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap/autoload.php';

use App\Controllers\CatalogueController;
use App\Repositories\ProductRepository;
use App\Services\PublicAssetPathResolver;

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
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$shop = $controller->shop([]);
$check($shop['totalProducts'] === 14 && count($shop['products']) === 12 && $shop['totalPages'] === 2, 'Active products are counted and paginated.');
$check($shop['products'][0]['slug'] === 'candle-01', 'Featured order is stable.');
$pageTwo = $controller->shop(['page' => '2']);
$check($pageTwo['currentPage'] === 2 && count($pageTwo['products']) === 2, 'Second page contains the remaining products.');
$check($controller->shop(['page' => '999'])['currentPage'] === 2, 'Out-of-range pages clamp to the last page.');
$check($controller->shop(['sort' => 'newest'])['products'][0]['slug'] === 'special-bloom', 'Newest order uses descending IDs.');
$check($controller->shop(['sort' => 'price-low'])['products'][0]['slug'] === 'candle-01', 'Ascending price order is applied in SQL.');
$check($controller->shop(['sort' => 'price-high', 'page' => '2'])['products'][1]['purchase_mode'] === 'REQUEST_ONLY', 'Request-only products sort after priced products.');
$check($controller->shop(['category' => 'candle-bouquets'])['totalProducts'] === 1, 'The public bouquet slug maps to the database category.');
$check($controller->shop(['query' => 'Special Bloom'])['products'][0]['slug'] === 'special-bloom', 'Search queries product names.');
$check($controller->shop(['query' => '%'])['totalProducts'] === 0, 'Search wildcards are treated literally.');
$check($controller->shop(['type' => 'flowers'])['totalProducts'] === 14, 'Product type filters query active products.');
$check($controller->shop(['type' => 'retired'])['selectedProductType'] === 'all', 'Inactive product types cannot be selected.');
$check($controller->shop(['category' => ['candles'], 'sort' => ['newest'], 'page' => ['2']])['currentPage'] === 1, 'Malformed filters and pages fall back safely.');

$category = $controller->category(['slug' => 'candle-bouquets', 'name' => 'Candle bouquets'], ['category' => 'candles']);
$check($category['totalProducts'] === 1 && $category['products'][0]['purchase_mode'] === 'REQUEST_ONLY', 'Category pages retain their own category and request-only product.');

define('BASE_URL', '/');
$filtered = $controller->shop([
    'category' => 'candles', 'type' => 'flowers', 'query' => 'Candle', 'sort' => 'newest',
]);
extract($filtered);
$cataloguePagePath = 'shop.php';
$catalogueView = ['class_prefix' => 'shop', 'loading_title' => 'Loading', 'error_title' => 'Error'];
ob_start();
require __DIR__ . '/../../app/Views/Components/catalogue-results.php';
$html = (string) ob_get_clean();
$check(str_contains($html, 'category=candles&amp;type=flowers&amp;query=Candle&amp;page=2')
    || (str_contains($html, 'category=candles') && str_contains($html, 'type=flowers')
        && str_contains($html, 'query=Candle') && str_contains($html, 'page=2')),
    'Pagination links preserve the active filters and search.');

echo "Public catalogue checks passed.\n";
