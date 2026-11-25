<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap/autoload.php';
require_once __DIR__ . '/../Support/TestCheck.php';

use App\Repositories\ProductRepository;
use App\Services\CartService;
use App\Services\ProductService;
use App\Services\PublicAssetPathResolver;

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY, slug TEXT, is_active INTEGER)');
$db->exec('CREATE TABLE category_translations (category_id INTEGER, language_code TEXT, name TEXT)');
$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, category_id INTEGER, slug TEXT, selling_price REAL, stock_quantity INTEGER, minimum_quantity INTEGER, purchase_mode TEXT, is_made_to_order INTEGER, can_purchase_alone INTEGER, main_image_path TEXT, is_active INTEGER)');
$db->exec('CREATE TABLE product_translations (product_id INTEGER, language_code TEXT, name TEXT, description TEXT)');
$db->exec('CREATE TABLE product_colors (product_id INTEGER, color_id INTEGER, image_path TEXT)');
$db->exec('CREATE TABLE product_photos (id INTEGER PRIMARY KEY, product_id INTEGER, file_path TEXT, sort_order INTEGER)');
$db->exec('CREATE TABLE colors (id INTEGER PRIMARY KEY, hex_code TEXT, is_active INTEGER)');
$db->exec('CREATE TABLE color_translations (color_id INTEGER, language_code TEXT, name TEXT)');
$db->exec('CREATE TABLE product_scents (product_id INTEGER, scent_id INTEGER)');
$db->exec('CREATE TABLE scents (id INTEGER PRIMARY KEY, is_active INTEGER)');
$db->exec('CREATE TABLE scent_translations (scent_id INTEGER, language_code TEXT, name TEXT)');
$db->exec("INSERT INTO categories VALUES (1, 'candles', 1), (2, 'accessories', 1), (3, 'bouquets', 1)");
$db->exec("INSERT INTO category_translations VALUES (1, 'en', 'Candles'), (2, 'en', 'Accessories'), (3, 'en', 'Bouquets')");
$db->exec("INSERT INTO products VALUES
    (1, 1, 'test-candle', 12.5, NULL, 2, 'CART', 1, 1, 'assets/products/.gitkeep', 1),
    (2, 2, 'test-accessory', 3.0, 3, 1, 'CART', 0, 0, 'assets/products/.gitkeep', 1),
    (3, 3, 'test-bouquet', 25.0, NULL, 1, 'CART', 1, 1, 'assets/products/.gitkeep', 1),
    (4, 1, 'inactive-candle', 10.0, NULL, 1, 'CART', 1, 1, 'assets/products/.gitkeep', 0),
    (5, 2, 'sold-out-accessory', 3.0, 0, 1, 'CART', 0, 0, 'assets/products/.gitkeep', 1)");
$db->exec("INSERT INTO product_translations VALUES
    (1, 'en', 'Test Candle', 'Candle'), (2, 'en', 'Test Accessory', 'Accessory'),
    (3, 'en', 'Test Bouquet', 'Bouquet'), (4, 'en', 'Inactive Candle', 'Hidden'),
    (5, 'en', 'Sold Out Accessory', 'Sold out')");
$db->exec("INSERT INTO colors VALUES (1, '#ffffff', 1), (2, '#000000', 0)");
$db->exec("INSERT INTO color_translations VALUES (1, 'en', 'White'), (2, 'en', 'Black')");
$db->exec("INSERT INTO product_colors VALUES
    (1, 1, 'assets/products/.gitkeep'), (1, 2, 'assets/products/.gitkeep'),
    (2, 1, 'assets/products/.gitkeep'), (5, 1, 'assets/products/.gitkeep')");
$db->exec("INSERT INTO scents VALUES (1, 1), (2, 0)");
$db->exec("INSERT INTO scent_translations VALUES (1, 'en', 'Vanilla'), (2, 'en', 'Retired')");
$db->exec('INSERT INTO product_scents VALUES (1, 1), (1, 2)');

$service = new CartService(new ProductService(
    new ProductRepository($db),
    new PublicAssetPathResolver(PUBLIC_PATH, PRODUCT_IMAGE_PATH)
));
$_SESSION = [];
$check = TestCheck::quiet();
$reject = static function (callable $operation, string $message) use ($check): void {
    try {
        $operation();
    } catch (DomainException) {
        return;
    }
    $check(false, $message);
};

$reject(fn () => $service->add(['slug' => 'test-accessory', 'color' => '1', 'quantity' => '1']), 'Accessory cannot be purchased alone.');
$reject(fn () => $service->add(['slug' => 'test-bouquet', 'quantity' => '1']), 'Request-only products cannot enter the cart.');
$reject(fn () => $service->add(['slug' => 'inactive-candle', 'color' => '1', 'scent' => '1', 'quantity' => '1']), 'Inactive products cannot enter the cart.');
$reject(fn () => $service->add(['slug' => 'test-candle', 'color' => '2', 'scent' => '1', 'quantity' => '2']), 'Inactive colours cannot be selected.');
$reject(fn () => $service->add(['slug' => 'test-candle', 'color' => '1', 'scent' => '2', 'quantity' => '2']), 'Inactive scents cannot be selected.');
$reject(fn () => $service->add(['slug' => 'test-candle', 'color' => '1', 'scent' => '1', 'quantity' => '1']), 'Minimum quantity is enforced.');
$service->add(['slug' => 'test-candle', 'color' => '1', 'scent' => '1', 'quantity' => '2']);
$service->add(['slug' => 'test-candle', 'color' => '1', 'scent' => '1', 'quantity' => '2']);
$items = $service->items();
$check(count($items) === 1 && $items[0]['quantity'] === 4 && $items[0]['price'] === 12.5, 'Matching choices merge and use the database price.');
$storedLine = reset($_SESSION['cart_lines']);
$check(!isset($storedLine['price'], $storedLine['name']) && $storedLine['quantity'] === 4, 'The session stores selections, not trusted prices or names.');
$candleLine = $items[0]['line_id'];
$service->add(['slug' => 'test-accessory', 'color' => '1', 'quantity' => '2']);
$reject(fn () => $service->add(['slug' => 'test-accessory', 'color' => '1', 'quantity' => '2']), 'Accessory stock is enforced.');
$reject(fn () => $service->add(['slug' => 'sold-out-accessory', 'color' => '1', 'quantity' => '1']), 'Out-of-stock accessories cannot enter the cart.');
$items = $service->items();
$accessoryLine = $items[1]['line_id'];
$reject(fn () => $service->remove(['line_id' => $candleLine]), 'The last candle cannot be removed while an accessory remains.');
$service->update(['line_id' => $accessoryLine, 'quantity' => '3']);
$reject(fn () => $service->update(['line_id' => $accessoryLine, 'quantity' => '4']), 'Stock is enforced on updates.');
$db->exec('UPDATE products SET selling_price = 15.0 WHERE id = 1');
$check($service->items()[0]['price'] === 15.0, 'Cart prices refresh from the database.');
$service->remove(['line_id' => $accessoryLine]);
$service->remove(['line_id' => $candleLine]);
$check($service->items() === [], 'Remove clears the stored lines.');

$service->add(['slug' => 'test-candle', 'color' => '1', 'scent' => '1', 'quantity' => '2']);
$service->add(['slug' => 'test-accessory', 'color' => '1', 'quantity' => '1']);
$db->exec('UPDATE products SET is_active = 0 WHERE id = 1');
$check($service->items() === [], 'Inactive products and orphaned accessories are removed from the session cart.');

echo "Cart checks passed.\n";
