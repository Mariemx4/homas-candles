<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Integration verification for Verify Checkout.
 *
 * DATA FLOW: This executable test crosses application and persistence boundaries to confirm the feature layers work together.
 */

// WHAT: drives CheckoutService against an in-memory SQLite copy of the
//       tables it touches, exactly like verify-cart.php does for the cart.
// WHY:  proves the 40 DT minimum, required-date window, authoritative
//       totals, deposit rule, snapshots and cart clearing without needing
//       the MySQL server. MySQL-only rules (CHECK constraints and the
//       migration 015 triggers) are covered by verify-migrations.php.
require_once __DIR__ . '/../../app/Bootstrap/autoload.php';

use App\Database\TransactionManager;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use App\Services\CartService;
use App\Services\CheckoutService;
use App\Services\ProductService;
use App\Services\PublicAssetPathResolver;

// DATABASE SETUP: Build only the catalogue and order tables this workflow reads and writes.
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
// OrderRepository::findCustomer() and findStatusHistory() use MySQL's CONCAT().
$db->sqliteCreateFunction('CONCAT', static fn (?string ...$parts): string => implode('', $parts));
$db->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY, slug TEXT, is_active INTEGER)');
$db->exec('CREATE TABLE category_translations (category_id INTEGER, language_code TEXT, name TEXT)');
$db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, category_id INTEGER, slug TEXT, selling_price REAL, stock_quantity INTEGER, minimum_quantity INTEGER, purchase_mode TEXT, is_made_to_order INTEGER, can_purchase_alone INTEGER, main_image_path TEXT, is_active INTEGER, actual_cost TEXT)');
$db->exec('CREATE TABLE product_translations (product_id INTEGER, language_code TEXT, name TEXT, description TEXT)');
$db->exec('CREATE TABLE product_colors (product_id INTEGER, color_id INTEGER, image_path TEXT)');
$db->exec('CREATE TABLE colors (id INTEGER PRIMARY KEY, hex_code TEXT, is_active INTEGER)');
$db->exec('CREATE TABLE color_translations (color_id INTEGER, language_code TEXT, name TEXT)');
$db->exec('CREATE TABLE product_scents (product_id INTEGER, scent_id INTEGER)');
$db->exec('CREATE TABLE scents (id INTEGER PRIMARY KEY, is_active INTEGER)');
$db->exec('CREATE TABLE scent_translations (scent_id INTEGER, language_code TEXT, name TEXT)');
$db->exec('CREATE TABLE customers_accounts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, phone_number TEXT)');
$db->exec('CREATE TABLE customer_addresses (id INTEGER PRIMARY KEY, customer_id INTEGER, street_address TEXT, delegation TEXT, governorate TEXT, postal_code TEXT, is_primary INTEGER)');
$db->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, order_number TEXT UNIQUE, customer_id INTEGER, created_by_administrator_id INTEGER,
    language_code TEXT, order_source TEXT, order_status TEXT, delivery_method TEXT, required_date TEXT, customer_name_snapshot TEXT,
    customer_email_snapshot TEXT, customer_phone_snapshot TEXT, street_address_snapshot TEXT, delegation_snapshot TEXT,
    governorate_snapshot TEXT, postal_code_snapshot TEXT, subtotal TEXT, delivery_fee TEXT, total_amount TEXT,
    required_deposit_amount TEXT, price_locked_at TEXT, customer_notes TEXT, administrator_notes TEXT,
    deleted_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE order_items (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, product_id INTEGER, product_name_snapshot TEXT,
    selected_color_id INTEGER, color_name_snapshot TEXT, selected_scent_id INTEGER, scent_name_snapshot TEXT, quantity INTEGER,
    unit_price_snapshot TEXT, unit_cost_snapshot TEXT, line_total_snapshot TEXT, customization_notes TEXT)');
foreach (['promo_code_snapshot','promo_type_snapshot','promo_value_snapshot','promo_discount_amount','manual_discount_type','manual_discount_value','manual_discount_reason','manual_discount_amount','quantity_savings_snapshot'] as $column) $db->exec('ALTER TABLE orders ADD COLUMN ' . $column . ' TEXT');
foreach (['catalogue_unit_price_snapshot','quantity_unit_price_snapshot','quantity_tier_snapshot','price_override_reason','recipe_source'] as $column) $db->exec('ALTER TABLE order_items ADD COLUMN ' . $column . ' TEXT');
$db->exec('CREATE TABLE order_status_history (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, previous_status TEXT, new_status TEXT,
    change_source TEXT, changed_by_administrator_id INTEGER, change_note TEXT, changed_at TEXT DEFAULT CURRENT_TIMESTAMP)');
$db->exec('CREATE TABLE administrators_accounts (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT)');
$db->exec('CREATE TABLE payments (id INTEGER PRIMARY KEY, order_id INTEGER, amount TEXT, payment_status TEXT)');
$db->exec("INSERT INTO categories VALUES (1, 'candles', 1)");
$db->exec("INSERT INTO category_translations VALUES (1, 'en', 'Candles')");
$db->exec("INSERT INTO products VALUES (1, 1, 'test-candle', 12.345, NULL, 1, 'CART', 1, 1, 'assets/products/.gitkeep', 1, '6.000')");
$db->exec("INSERT INTO product_translations VALUES (1, 'en', 'Test Candle', 'Candle')");
$db->exec("INSERT INTO colors VALUES (1, '#ffffff', 1)");
$db->exec("INSERT INTO color_translations VALUES (1, 'en', 'White')");
$db->exec("INSERT INTO product_colors VALUES (1, 1, 'assets/products/.gitkeep')");
$db->exec("INSERT INTO scents VALUES (1, 1)");
$db->exec('INSERT INTO product_scents VALUES (1, 1)');
$db->exec("INSERT INTO scent_translations VALUES (1, 'en', 'Vanilla')");
$db->exec("INSERT INTO customers_accounts VALUES (7, 'Amira', 'Ben Salah', 'amira@example.com', '22123456')");
$db->exec("INSERT INTO customer_addresses VALUES (1, 7, '12 Rue des Roses', 'La Marsa', 'Tunis', '2070', 1)");

$cart = new CartService(new ProductService(
    new ProductRepository($db),
    new PublicAssetPathResolver(PUBLIC_PATH, PRODUCT_IMAGE_PATH)
));
$orders = new OrderRepository($db);
$settings = require APP_PATH . '/config/checkout.php';
$governorates = require APP_PATH . '/config/governorates.php';
$sequence = 0;
$checkout = new CheckoutService(
    $cart,
    $orders,
    new TransactionManager($db),
    $settings,
    $governorates,
    static function () use (&$sequence): string {
        return 'ORD-C-TEST-' . str_pad((string) ++$sequence, 4, '0', STR_PAD_LEFT);
    },
    static fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-29')
);
$_SESSION = [];
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
// TEST INPUT: A valid delivery submission; individual checks override one field at a time.
$validInput = static fn (array $overrides = []): array => $overrides + [
    'customer_name' => '  Amira   Ben Salah ',
    'customer_email' => 'amira@example.com',
    'customer_phone' => '+216 22 123 456',
    'delivery_method' => 'DELIVERY',
    'street_address' => '12 Rue des Roses',
    'delegation' => 'La Marsa',
    'governorate' => 'Tunis',
    'postal_code' => '2070',
    'required_date' => '2026-10-05',
    'customer_notes' => 'Happy birthday!',
    'accept_terms' => '1',
    'cart_signature' => $checkout->summary()['signature'],
];

// 1. Minimum order: 3 × 12.345 = 37.035 DT is below 40 DT.
$cart->add(['slug' => 'test-candle', 'color' => '1', 'scent' => '1', 'quantity' => '3']);
$summary = $checkout->summary();
$check(!$summary['meets_minimum'] && $summary['shortfall_millis'] === 2965, 'The 40 DT minimum reports the exact shortfall.');
$result = $checkout->place($validInput(), null);
$check(!$result['success'] && isset($result['errors']['form']), 'Orders below 40 DT are refused.');

// 2. Field validation: date window, address, and acknowledgement.
$cart->update(['line_id' => $summary['items'][0]['line_id'], 'quantity' => '4']);
$result = $checkout->place($validInput(['required_date' => '2026-09-30']), null);
$check(isset($result['errors']['required_date']), 'A date inside the preparation window is refused.');
$result = $checkout->place($validInput(['required_date' => '2027-06-01']), null);
$check(isset($result['errors']['required_date']), 'A date beyond the booking window is refused.');
$result = $checkout->place($validInput(['required_date' => '2026-02-31']), null);
$check(isset($result['errors']['required_date']), 'An impossible calendar date is refused.');
$result = $checkout->place($validInput(['governorate' => 'Atlantis', 'postal_code' => '12']), null);
$check(isset($result['errors']['governorate'], $result['errors']['postal_code']), 'Delivery address fields are validated.');
$result = $checkout->place($validInput(['accept_terms' => '']), null);
$check(isset($result['errors']['accept_terms']), 'The preparation/payment acknowledgement is required.');
$result = $checkout->place($validInput(['customer_phone' => '123']), null);
$check(isset($result['errors']['customer_phone']), 'Invalid phone numbers are refused.');
$check((int) $db->query('SELECT COUNT(*) FROM orders')->fetchColumn() === 0, 'Rejected submissions write nothing.');

// 3. Authoritative totals: a price change after review forces a fresh review.
$staleInput = $validInput();
$db->exec('UPDATE products SET selling_price = 13.000 WHERE id = 1');
$result = $checkout->place($staleInput, null);
$check(!$result['success'] && isset($result['errors']['form']), 'A changed price invalidates the reviewed summary.');

// 4. Successful delivery order below the deposit threshold: 4 × 13.000 + 9.000 = 61.000 DT.
$result = $checkout->place($validInput(), 7);
$check($result['success'] && $result['orderNumber'] === 'ORD-C-TEST-0001', 'A valid checkout creates an order.');
$order = $orders->findSummary($result['orderId']);
$check($order['order_source'] === 'CART' && $order['order_status'] === 'REQUESTED', 'Checkout orders are REQUESTED cart orders.');
$check((int) $order['customer_id'] === 7, 'A signed-in customer is linked to the order.');
$check($order['subtotal'] === '52.000' && $order['delivery_fee'] === '9.000' && $order['total_amount'] === '61.000', 'Totals are recalculated on the server.');
$check($order['required_deposit_amount'] === '0.000', 'Orders under 100 DT need no deposit.');
$check($order['price_locked_at'] === null, 'Cart order prices stay negotiable until PREPARED.');
$check($order['customer_name_snapshot'] === 'Amira Ben Salah' && $order['customer_phone_snapshot'] === '22123456', 'Contact details are normalized into snapshots.');
$check($order['governorate_snapshot'] === 'Tunis' && $order['postal_code_snapshot'] === '2070', 'The delivery address is frozen on the order.');
$items = $orders->findItems($result['orderId']);
$check(count($items) === 1 && $items[0]['product_name_snapshot'] === 'Test Candle'
    && $items[0]['color_name_snapshot'] === 'White' && $items[0]['scent_name_snapshot'] === 'Vanilla'
    && $items[0]['unit_price_snapshot'] === '13.000' && $items[0]['line_total_snapshot'] === '52.000', 'Item snapshots are frozen.');
$check($items[0]['unit_cost_snapshot'] === '6.000', 'The production cost is frozen with the price.');
$history = $orders->findStatusHistory($result['orderId']);
$check(count($history) === 1 && $history[0]['change_source'] === 'CUSTOMER', 'The first history event is recorded as a customer action.');
$check($cart->items() === [], 'The cart is emptied after a successful order.');

// 5. Snapshots survive later catalogue changes.
$db->exec("UPDATE product_translations SET name = 'Renamed Candle' WHERE product_id = 1");
$db->exec('UPDATE products SET selling_price = 20.000 WHERE id = 1');
$items = $orders->findItems($result['orderId']);
$check($items[0]['product_name_snapshot'] === 'Test Candle' && $items[0]['unit_price_snapshot'] === '13.000', 'Catalogue edits do not change placed orders.');

// 6. Pickup order at or above 100 DT: 5 × 20.000 = 100.000 DT, no fee, 40% deposit, no address.
$cart->add(['slug' => 'test-candle', 'color' => '1', 'scent' => '1', 'quantity' => '5']);
$pickupSummary = $checkout->summary('PICKUP');
$check($pickupSummary['total_millis'] === 100000 && $pickupSummary['deposit_millis'] === 40000, 'The 40% deposit applies from 100 DT.');
$result = $checkout->place($validInput(['delivery_method' => 'PICKUP', 'street_address' => '', 'postal_code' => '', 'customer_email' => '']), null);
$check($result['success'], 'A pickup order does not need an address.');
$order = $orders->findSummary($result['orderId']);
$check($order['delivery_fee'] === '0.000' && $order['required_deposit_amount'] === '40.000', 'Pickup is free and the deposit is frozen.');
$check($order['street_address_snapshot'] === null && $order['customer_email_snapshot'] === null && $order['customer_id'] === null, 'Pickup guests store no address or email.');

// 7. Resubmitting after success finds an empty cart instead of duplicating the order.
$result = $checkout->place($validInput(), null);
$check(!$result['success'] && (int) $db->query('SELECT COUNT(*) FROM orders')->fetchColumn() === 2, 'A double submission creates no duplicate order.');

// RESULT STEP: Report success only after every assertion above has passed.
echo "Checkout checks passed.\n";
