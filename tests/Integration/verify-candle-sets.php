<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Integration verification for Verify Candle Sets.
 *
 * DATA FLOW: This executable test crosses application and persistence boundaries to confirm the feature layers work together.
 */

// WHAT: checks the two documented candle-set rules against an in-memory
//       SQLite copy of the catalogue tables:
//         1. a set's product page lists its components with quantities,
//            prices and product-page links;
//         2. a set appears under every product type of its components, and
//            follows component changes without any manual resync.
// SETUP STEP: Load the application code or delegated test required before this command can run.
require_once __DIR__ . '/../../app/Bootstrap/autoload.php';

use App\Controllers\CatalogueController;
use App\Controllers\ProductController;
use App\Repositories\ProductRepository;
use App\Services\ProductService;
use App\Services\PublicAssetPathResolver;

// DATABASE SETUP: Build only the catalogue tables the product page and filters read.
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY, slug TEXT, is_active INTEGER)');
$db->exec('CREATE TABLE category_translations (category_id INTEGER, language_code TEXT, name TEXT)');
$db->exec('CREATE TABLE product_types (id INTEGER PRIMARY KEY, slug TEXT, is_active INTEGER)');
$db->exec('CREATE TABLE product_type_translations (product_type_id INTEGER, language_code TEXT, name TEXT)');
$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, category_id INTEGER, product_type_id INTEGER, slug TEXT, selling_price REAL, stock_quantity INTEGER, minimum_quantity INTEGER, purchase_mode TEXT, is_made_to_order INTEGER, can_purchase_alone INTEGER, main_image_path TEXT, is_active INTEGER)');
$db->exec('CREATE TABLE product_translations (product_id INTEGER, language_code TEXT, name TEXT, description TEXT)');
$db->exec('CREATE TABLE product_colors (product_id INTEGER, color_id INTEGER, image_path TEXT)');
$db->exec('CREATE TABLE colors (id INTEGER PRIMARY KEY, hex_code TEXT, is_active INTEGER)');
$db->exec('CREATE TABLE color_translations (color_id INTEGER, language_code TEXT, name TEXT)');
$db->exec('CREATE TABLE product_scents (product_id INTEGER, scent_id INTEGER)');
$db->exec('CREATE TABLE scents (id INTEGER PRIMARY KEY, is_active INTEGER)');
$db->exec('CREATE TABLE scent_translations (scent_id INTEGER, language_code TEXT, name TEXT)');
$db->exec('CREATE TABLE set_components (set_product_id INTEGER, component_product_id INTEGER, quantity INTEGER)');

$db->exec("INSERT INTO categories VALUES (1, 'candles', 1), (2, 'candle-sets', 1), (3, 'accessories', 1)");
$db->exec("INSERT INTO category_translations VALUES (1, 'en', 'Candles'), (2, 'en', 'Candle sets'), (3, 'en', 'Accessories')");
$db->exec("INSERT INTO product_types VALUES (1, 'flowers', 1), (2, 'bubble', 1), (3, 'accessories', 1), (4, 'other', 1), (5, 'retired', 0)");
$db->exec("INSERT INTO product_type_translations VALUES (1, 'en', 'Flowers'), (2, 'en', 'Bubble'), (3, 'en', 'Accessories'), (4, 'en', 'Other'), (5, 'en', 'Retired')");
$image = 'assets/products/.gitkeep';
$product = $db->prepare("INSERT INTO products VALUES (?, ?, ?, ?, ?, NULL, 1, 'CART', 1, 1, '{$image}', ?)");
$name = $db->prepare("INSERT INTO product_translations VALUES (?, 'en', ?, 'Handmade')");
foreach ([
    [1, 1, 1, 'rose-candle', 12.5, 1, 'Rose Candle'],
    [2, 1, 2, 'bubble-candle', 8.25, 1, 'Bubble Candle'],
    [3, 3, 3, 'gift-ribbon', 2.0, 1, 'Gift Ribbon'],
    [4, 1, 5, 'retired-candle', 6.0, 0, 'Retired Candle'],
    // The set's own product type is "other"; its components carry the real types.
    [10, 2, 4, 'duo-set', 40.0, 1, 'Duo Set'],
] as [$id, $category, $type, $slug, $price, $active, $label]) {
    $product->execute([$id, $category, $type, $slug, $price, $active]);
    $name->execute([$id, $label]);
}
$db->exec('INSERT INTO set_components VALUES (10, 1, 2), (10, 2, 1), (10, 3, 1)');

$images = new PublicAssetPathResolver(PUBLIC_PATH, PRODUCT_IMAGE_PATH);
$repository = new ProductRepository($db);
$products = new ProductController(new ProductService($repository, $images));
$catalogue = new CatalogueController($repository, $images);
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$slugs = static fn (array $query): array => array_column($catalogue->shop($query)['products'], 'slug');

// 1. The set's page lists components, candles before accessories.
$view = $products->show('duo-set');
$components = $view['components'];
$check(array_column($components, 'name') === ['Bubble Candle', 'Rose Candle', 'Gift Ribbon'], 'Components are listed, candles first, accessories last.');
$rose = $components[1];
$check($rose['quantity'] === 2 && $rose['unit_price_millis'] === 12500 && $rose['line_total_millis'] === 25000, 'Quantities, unit prices and line totals are exact.');
$check($rose['slug'] === 'rose-candle' && $rose['type_name'] === 'Flowers' && $rose['image'] === $image, 'Each component links to its own page with type and image.');
$check($products->show('rose-candle')['components'] === [], 'Products that are not sets have no components.');

// 2. A retired component is still listed, but not linked or priced.
$db->exec('INSERT INTO set_components VALUES (10, 4, 1)');
$retired = array_values(array_filter($products->show('duo-set')['components'], static fn (array $row): bool => $row['id'] === 4))[0];
$check($retired['slug'] === null && $retired['unit_price_millis'] === null && $retired['is_available'] === false, 'Inactive components are shown without a link or price.');
$db->exec('DELETE FROM set_components WHERE component_product_id = 4');

// 3. The rendered page shows the breakdown with links and the separate total.
define('BASE_URL', '/');
// Render the same component view the product page includes.
$render = static function (array $components): string {
    ob_start();
    require APP_PATH . '/Views/Components/product-set-components.php';
    return (string) ob_get_clean();
};
$html = $render($products->show('duo-set')['components']);
$check(trim($render([])) === '', 'Products without components render nothing.');
$check(str_contains($html, 'What’s in this set') && str_contains($html, 'href="/product.php?slug=rose-candle"'), 'The page links each component.');
$check(str_contains($html, '× 2') && str_contains($html, '25.000 DT') && str_contains($html, '12.500 DT each'), 'The page shows quantities and prices.');
$check(str_contains($html, 'Bought separately: <strong>35.250 DT</strong>'), 'The separate total adds every line exactly.');

// 4. Type filters include sets through their components' types.
$check(in_array('duo-set', $slugs(['type' => 'flowers']), true), 'The set appears under its flower candle type.');
$check(in_array('duo-set', $slugs(['type' => 'bubble']), true), 'The set appears under its bubble candle type.');
$check(in_array('duo-set', $slugs(['type' => 'other']), true), 'The set still appears under its own type.');
$check($slugs(['type' => 'flowers']) === ['rose-candle', 'duo-set'], 'Type filters do not pull in unrelated products.');
$check($catalogue->shop(['type' => 'bubble'])['totalProducts'] === 2, 'Counts include sets matched through components.');

// 5. Visibility follows component edits immediately.
$db->exec('DELETE FROM set_components WHERE component_product_id = 2');
$check(!in_array('duo-set', $slugs(['type' => 'bubble']), true), 'Removing a component removes the set from that type.');
$db->exec("UPDATE product_types SET is_active = 0 WHERE slug = 'flowers'");
$check($catalogue->shop(['type' => 'flowers'])['selectedProductType'] === 'all', 'An inactive component type is not offered as a filter.');

// RESULT STEP: Report success only after every assertion above has passed.
echo "Candle set checks passed.\n";
