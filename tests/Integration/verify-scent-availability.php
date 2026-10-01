<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Integration verification for Verify Scent Availability.
 *
 * DATA FLOW: This executable test crosses application and persistence boundaries to confirm the feature layers work together.
 */

// WHAT: proves that a scent is sold only while its fragrance material can
//       supply it, using the real services against a throwaway MySQL/MariaDB
//       database (tests/Support/throwaway-database.php).
// WHY:  public scent retrieval used to check only scents.is_active. Now
//       ScentAvailabilityService derives availability from the linked
//       fragrance material and the product's fragrance quantity, the cart and
//       every order entry point revalidate it with the rows locked, and the
//       PREPARED deduction consumes the CHOSEN scent's oil.
// SETUP STEP: Load the application code and the throwaway-database helper.
require_once __DIR__ . '/../Support/throwaway-database.php';

use App\Database\TransactionManager;
use App\Repositories\AccessoryStockRepository;
use App\Repositories\MaterialStockRepository;
use App\Repositories\OrderProductionRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderStatusRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ScentStockRepository;
use App\Services\AccessoryStockService;
use App\Services\CartService;
use App\Services\CheckoutService;
use App\Services\ManualOrderService;
use App\Services\OrderProductionService;
use App\Services\OrderStatusService;
use App\Services\PricingService;
use App\Services\ProductService;
use App\Services\PublicAssetPathResolver;
use App\Services\ScentAvailabilityService;

// DATABASE SETUP: A fresh database with every migration and seed.sql.
[$db, $databaseName] = create_throwaway_database('scentstock');
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "[PASS] {$message}\n";
};

try {
    // Wax is untracked; each fragrance oil is tracked in millilitres.
    // Lavender's oil is retired (inactive); "Unscented" has no material at all.
    $db->exec("INSERT INTO materials (id, material_type_id, sku, name, unit, stock_quantity, unit_cost, tracks_stock, is_active) VALUES
        (1, 1, 'WAX', 'Soy wax', 'kg', NULL, 100.000, 0, 1),
        (6, 1, 'OIL-VANILLA', 'Vanilla fragrance oil', 'millilitre', 100.000, 0.080, 1, 1),
        (7, 1, 'OIL-ROSE', 'Rose fragrance oil', 'millilitre', 20.000, 0.090, 1, 1),
        (8, 1, 'OIL-LAVENDER', 'Lavender fragrance oil', 'millilitre', 500.000, 0.085, 1, 0)");
    $db->exec("INSERT INTO scents (id, fragrance_material_id, slug, is_active) VALUES
        (1, 6, 'vanilla', 1), (2, 7, 'rose', 1), (3, 8, 'lavender', 1), (4, NULL, 'unscented', 1)");
    $db->exec("INSERT INTO scent_translations (scent_id, language_code, name) VALUES
        (1, 'en', 'Vanilla'), (2, 'en', 'Rose'), (3, 'en', 'Lavender'), (4, 'en', 'Unscented')");
    $db->exec("INSERT INTO colors (id, slug, hex_code, is_active) VALUES (1, 'ivory', '#FFFFF0', 1)");
    $db->exec("INSERT INTO color_translations (color_id, language_code, name) VALUES (1, 'en', 'Ivory')");
    // 1: a candle whose recipe holds 8 ml of vanilla oil as its scent slot; 2: a set of 2 candles.
    $db->exec("INSERT INTO products (id, category_id, product_type_id, slug, minimum_quantity, purchase_mode, main_image_path, is_active) VALUES
        (1, 1, 1, 'rose-candle', 1, 'CART', 'assets/products/.gitkeep', 1),
        (2, 2, NULL, 'candle-duo', 1, 'CART', NULL, 1)");
    $db->exec("INSERT INTO product_translations (product_id, language_code, name, description) VALUES
        (1, 'en', 'Scented Candle', 'Handmade'), (2, 'en', 'Candle Duo', 'Set')");
    $db->exec("INSERT INTO product_colors (product_id, color_id, image_path) VALUES (1, 1, 'assets/products/.gitkeep'), (2, 1, NULL)");
    $db->exec('INSERT INTO product_scents (product_id, scent_id) VALUES (1, 1), (1, 2), (1, 3), (1, 4), (2, 1), (2, 2)');
    $db->exec("INSERT INTO product_materials (product_id, material_id, recipe_type, quantity_required) VALUES
        (1, 1, 'ACTUAL', 0.200), (1, 6, 'ACTUAL', 8.000)");
    $db->exec('INSERT INTO set_components (set_product_id, component_product_id, quantity) VALUES (2, 1, 2)');
    $db->exec("INSERT INTO administrators_accounts (id, first_name, last_name, email, phone_number, password_hash, account_status)
        VALUES (1, 'Test', 'Administrator', 'admin@scent.test', '20000002', 'not-a-real-hash', 'ACTIVE')");

    // The real services, wired as in app/Bootstrap/*.php.
    $transactions = new TransactionManager($db);
    (new PricingService(new PricingRepository($db), $transactions))->recalculateAll();
    $scentStock = new ScentStockRepository($db);
    $scents = new ScentAvailabilityService($scentStock, $transactions);
    $products = new ProductService(new ProductRepository($db), new PublicAssetPathResolver(PUBLIC_PATH, PRODUCT_IMAGE_PATH), $scents);
    $_SESSION = [];
    $cart = new CartService($products);
    $orders = new OrderRepository($db);
    $accessories = new AccessoryStockService(new AccessoryStockRepository($db), $transactions);
    $checkout = new CheckoutService($cart, $orders, $transactions, require APP_PATH . '/config/checkout.php',
        require APP_PATH . '/config/governorates.php', null, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-29'),
        $accessories, $scents);
    $productionRepository = new OrderProductionRepository($db);
    $production = new OrderProductionService($productionRepository, $transactions);
    $manual = new ManualOrderService($orders, $transactions, $productionRepository, $production, $accessories, $scents);
    $statuses = new OrderStatusService(new OrderStatusRepository($db), new MaterialStockRepository($db, $transactions), $transactions, $production, $accessories);
    $availability = static function () use ($products): array {
        $result = [];
        foreach ($products->findForCatalogue('rose-candle')['scents'] as $scent) {
            $result[$scent['name']] = $scent['is_available'];
        }

        return $result;
    };
    $stock = static fn (int $id): string => (string) $db->query("SELECT stock_quantity FROM materials WHERE id = {$id}")->fetchColumn();
    $orderCount = static fn (): int => (int) $db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $checkoutInput = static fn () => [
        'customer_name' => 'Amira Ben Salah', 'customer_email' => '', 'customer_phone' => '22123456',
        'delivery_method' => 'PICKUP', 'street_address' => '', 'delegation' => '', 'governorate' => '', 'postal_code' => '',
        'required_date' => '2026-10-10', 'customer_notes' => '', 'accept_terms' => '1',
        'cart_signature' => $checkout->summary('PICKUP')['signature'],
    ];
    $manualOrder = static fn (array $items): array => [
        'customer_name' => 'Manual Customer', 'customer_email' => '', 'customer_phone' => '22123457',
        'delivery_method' => 'PICKUP', 'required_date' => '2026-12-10', 'delivery_fee' => '0.000',
        'street_address' => '', 'delegation' => '', 'governorate' => '', 'postal_code' => '',
        'customer_notes' => '', 'administrator_notes' => '', 'customer_id' => '', 'items' => $items,
    ];
    $item = static fn (int $productId, string $scentId, int $quantity): array => ['product_id' => (string) $productId, 'color_id' => '1',
        'scent_id' => $scentId, 'quantity' => (string) $quantity, 'unit_price' => '', 'customization_notes' => ''];

    // 1. The product page derives availability from the fragrance material.
    $check($scentStock->fragrancePerUnit([1, 2]) === [1 => 8000, 2 => 16000], 'A candle needs 8 ml of fragrance; the duo set needs 2 × 8 = 16 ml.');
    $check($availability() === ['Vanilla' => true, 'Rose' => true, 'Lavender' => false, 'Unscented' => true],
        'Scents with oil in stock are available; a retired oil makes its scent unavailable; a scent with no oil cannot run out.');

    // 2. Checkout revalidates the chosen scent for the whole quantity.
    $cart->add(['slug' => 'rose-candle', 'color' => '1', 'scent' => '2', 'quantity' => '3']);
    $refused = $checkout->place($checkoutInput(), null);
    $check(!$refused['success'] && str_contains($refused['errors']['form'], 'Rose (24.000 millilitre needed, 20.000 available)') && $orderCount() === 0,
        'Checkout refuses 3 rose candles (24 ml) with 20 ml of rose oil, and saves nothing.');
    $cart->update(['line_id' => $cart->items()[0]['line_id'], 'quantity' => '2']);
    $placed = $checkout->place($checkoutInput(), null);
    $check($placed['success'] === true, 'Checkout accepts 2 rose candles (16 ml).');

    // 3. Promised fragrance counts against later customers.
    $check($availability()['Rose'] === false && $availability()['Vanilla'] === true, 'With 16 ml promised, rose (4 ml left) is shown as unavailable.');
    $cartRefused = false;
    try {
        $cart->add(['slug' => 'rose-candle', 'color' => '1', 'scent' => '2', 'quantity' => '1']);
    } catch (DomainException $exception) {
        $cartRefused = str_contains($exception->getMessage(), 'out of stock');
    }
    $check($cartRefused, 'The cart refuses an unavailable scent.');
    $lavenderRefused = false;
    try {
        $cart->add(['slug' => 'rose-candle', 'color' => '1', 'scent' => '3', 'quantity' => '1']);
    } catch (DomainException) {
        $lavenderRefused = true;
    }
    $check($lavenderRefused, 'The cart refuses a scent whose oil is retired.');

    // 4. Manual orders revalidate too, adding up lines that share one oil.
    $before = $orderCount();
    $roseManual = $manual->create(1, $manualOrder([$item(1, '2', 1)]));
    $check(!$roseManual['success'] && str_contains($roseManual['errors']['items'], '8.000 millilitre needed, 4.000 available') && $orderCount() === $before,
        'A manual rose order is refused while only 4 ml are free.');
    $combined = $manual->create(1, $manualOrder([$item(1, '1', 10), $item(2, '1', 2)]));
    $check(!$combined['success'] && str_contains($combined['errors']['items'], 'Vanilla (112.000 millilitre needed, 100.000 available)'),
        'Two lines sharing vanilla oil are judged together (10 × 8 + 2 × 16 = 112 ml > 100 ml).');
    $duo = $manual->create(1, $manualOrder([$item(2, '1', 2)]));
    $check($duo['success'] === true && $availability()['Vanilla'] === true, 'Two vanilla duo sets (32 ml) are accepted; vanilla stays available (68 ml free).');

    // 5. Preparation consumes the chosen scent's oil, not the recipe's example oil.
    $check($statuses->selectStatus($placed['orderId'], 1, 'PREPARED', 'REQUESTED')['success'], 'The rose order is prepared.');
    $check($stock(7) === '4.000' && $stock(6) === '100.000', 'Preparing deducts 16 ml of rose oil (20 → 4) and no vanilla oil.');
    // The duo order is over 100 DT, so its 40% deposit must be verified before it can be confirmed.
    $db->prepare("INSERT INTO payments (order_id, payment_method, payment_type, payment_status, amount, currency, verified_at)
        SELECT id, 'CASH_ON_DELIVERY', 'DEPOSIT', 'VERIFIED', required_deposit_amount, 'TND', NOW() FROM orders WHERE id = :id")
        ->execute(['id' => $duo['orderId']]);
    $check($statuses->selectStatus($duo['orderId'], 1, 'PREPARED', 'REQUESTED')['success'] && $stock(6) === '68.000',
        'A set\'s components use the set\'s chosen scent (2 sets × 2 candles × 8 ml = 32 ml of vanilla).');
    $check($availability()['Rose'] === false, 'After preparation the deducted stock itself keeps rose unavailable.');
    $db->exec('UPDATE materials SET stock_quantity = 50.000 WHERE id = 7');
    $check($availability()['Rose'] === true, 'Restocking the rose oil makes the scent available again.');

    // A stale form must not accept a scent retired or removed after it loaded.
    $db->exec('UPDATE scents SET is_active = 0 WHERE id = 2');
    $check($scents->availability(1, 1, [2])[2] === false, 'A retired scent is unavailable even if its oil is in stock.');
    $check($scents->availability(999999, 1, [1])[1] === false,
        'A stocked fragrance cannot be offered when the product has no required fragrance quantity.');
    foreach ([2, 999999] as $staleScentId) {
        $rejected = false;
        try {
            $scents->assertAvailable([['product_id' => 1, 'scent_id' => $staleScentId, 'quantity' => 1]]);
        } catch (DomainException) {
            $rejected = true;
        }
        $check($rejected, 'Order acceptance rejects a retired or missing scent.');
    }
} finally {
    // Always remove the throwaway database, success or failure.
    drop_throwaway_database($db, $databaseName);
}

// RESULT STEP: Report success only after every assertion above has passed.
echo "Scent availability checks passed.\n";
