<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Integration verification for Verify Pricing Workflow.
 *
 * DATA FLOW: This executable test crosses application and persistence boundaries to confirm the feature layers work together.
 */

// WHAT: runs PricingService and the services that trigger it against real
//       PostgreSQL, in a temporary schema built from every migration and
//       seed.sql — the same approach as verify-migrations.php — then drops it.
// WHY:  proves what the SQLite unit test cannot: the locking SQL, the new
//       CHECK constraints (migration 016), that a material-cost edit reprices
//       the catalogue in the same transaction, and that agreed order prices
//       stay frozen. Your real homas_candles data is never touched.
// SETUP STEP: Load the application code and the temporary schema helper.
require_once __DIR__ . '/../Support/throwaway-auth-schema.php';

use App\Database\TransactionManager;
use App\Repositories\AdminProductRepository;
use App\Repositories\MaterialMovementRepository;
use App\Repositories\MaterialRepository;
use App\Repositories\MaterialStockRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductRecipeRepository;
use App\Services\MaterialService;
use App\Services\PricingService;
use App\Services\ProductReadinessService;
use App\Services\ProductRecipeService;

// DATABASE SETUP: A fresh database with every migration and seed.sql (tests/Support).
[$db, $databaseName] = create_throwaway_auth_schema(true);
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "[PASS] {$message}\n";
};

try {

    // Materials: wax 25.000/kg, wick 0.350, labour 4.000/h, ribbon 3.000, candle-equivalent wax 30.000.
    $db->exec("INSERT INTO materials (id, material_type_id, sku, name, unit, stock_quantity, unit_cost, tracks_stock, is_active) VALUES
        (1, 1, 'WAX', 'Soy wax', 'kg', NULL, 25.000, 0, 1),
        (2, 1, 'WICK', 'Wick', 'piece', NULL, 0.350, 0, 1),
        (3, 6, 'LABOUR', 'Labour', 'hour', NULL, 4.000, 0, 1),
        (4, 4, 'RIBBON', 'Gift ribbon', 'piece', NULL, 3.000, 0, 1),
        (5, 1, 'GEL', 'Diffuser base', 'litre', NULL, 8.000, 0, 1)");
    // Products (category ids from seed.sql): 1 candles, 2 sets, 4 event favors, 5 diffusers, 6 accessories.
    // Product 7 starts with a legacy manual price and no recipe.
    $db->exec("INSERT INTO products (id, category_id, product_type_id, pricing_reference_product_id, slug, selling_price, minimum_quantity, purchase_mode, is_active) VALUES
        (1, 1, 1, NULL, 'rose-candle', NULL, 1, 'CART', 1),
        (2, 6, 7, NULL, 'gift-ribbon', NULL, 1, 'CART', 1),
        (3, 2, NULL, NULL, 'duo-set', NULL, 1, 'CART', 1),
        (4, 5, 3, 1, 'rose-diffuser', NULL, 1, 'CART', 1),
        (5, 4, 6, NULL, 'mini-favor', NULL, 10, 'REQUEST_ONLY', 1),
        (7, 1, 1, NULL, 'legacy-candle', 99.000, 1, 'CART', 1)");
    $db->exec("INSERT INTO product_materials (product_id, material_id, recipe_type, quantity_required) VALUES
        (1, 1, 'ACTUAL', 0.200), (1, 2, 'ACTUAL', 1.000), (1, 3, 'ACTUAL', 0.500),
        (2, 4, 'ACTUAL', 1.000),
        (4, 5, 'ACTUAL', 0.250), (4, 3, 'ACTUAL', 0.250),
        (5, 1, 'ACTUAL', 0.050), (5, 2, 'ACTUAL', 1.000)");
    $db->exec('INSERT INTO set_components (set_product_id, component_product_id, quantity) VALUES (3, 1, 2), (3, 2, 1)');

    $transactions = new TransactionManager($db);
    $pricing = new PricingService(new PricingRepository($db), $transactions);
    $readiness = new ProductReadinessService(new AdminProductRepository($db), $pricing, $transactions);
    $price = static fn (int $id): array => $db->query("SELECT selling_price, actual_cost, price_rule, price_calculated_at, pricing_issue FROM products WHERE id = {$id}")->fetch();

    // 1. First calculation applies every rule.
    $summary = $pricing->recalculateAll();
    $check($price(1)['selling_price'] === '14.700' && $price(1)['actual_cost'] === '7.350' && $price(1)['price_rule'] === 'CANDLE_COST_X2', 'Candle: 7.350 cost × 2 = 14.700.');
    $check($price(2)['selling_price'] === '3.000' && $price(2)['price_rule'] === 'ACCESSORY_PURCHASE_COST', 'Accessory is sold at its 3.000 purchase cost.');
    $check($price(3)['selling_price'] === '32.400' && $price(3)['actual_cost'] === '17.700', 'Set: 2 × 7.350 × 2 + 3.000 ribbon = 32.400.');
    $check($price(4)['selling_price'] === '14.700' && $price(4)['actual_cost'] === '3.000', 'Diffuser shows its candle\'s price but keeps its own 3.000 cost.');
    $check($price(5)['selling_price'] === '2.800' && $price(5)['price_rule'] === 'EVENT_FAVOR_TIER', 'Event favor: 1.600 unit cost × 1.75 = 2.800 per favor.');
    $check($price(7)['selling_price'] === '99.000' && $price(7)['price_rule'] === null && $price(7)['pricing_issue'] !== null, 'A product without a recipe keeps its legacy price and explains why.');
    $check(isset($summary['unpriced'][7]) && count($summary['unpriced']) === 1, 'The summary lists the one unpriced product.');

    // An order agreed at today's prices (price-locked, as at PREPARED).
    $db->exec("INSERT INTO orders (id, order_number, language_code, order_source, order_status, delivery_method, required_date,
            customer_name_snapshot, customer_phone_snapshot, subtotal, delivery_fee, total_amount, required_deposit_amount, price_locked_at)
        VALUES (1, 'ORD-TEST-0001', 'en', 'MANUAL', 'PREPARED', 'PICKUP', '2026-10-10', 'Test Customer', '22123456', 14.700, 0, 14.700, 0, NOW())");
    $db->exec("INSERT INTO order_items (order_id, product_id, product_name_snapshot, quantity, unit_price_snapshot, line_total_snapshot)
        VALUES (1, 1, 'Rose Candle', 1, 14.700, 14.700)");
    $calculatedAt = $price(1)['price_calculated_at'];

    // 2. A second run with no changes rewrites nothing.
    $check($pricing->recalculateAll()['updated'] === 0 && $price(1)['price_calculated_at'] === $calculatedAt, 'An unchanged recalculation writes nothing.');

    // 3. A material-cost edit reprices every dependant in the same transaction.
    $materials = new MaterialService(
        new MaterialRepository($db),
        new MaterialMovementRepository($db),
        new MaterialStockRepository($db, $transactions),
        $transactions,
        $readiness
    );
    $saved = $materials->save(1, ['name' => 'Soy wax', 'unit_cost' => '30.000', 'minimum_stock_level' => '2.000', 'is_active' => '1']);
    $check($saved['success'] === true, 'The wax cost is saved.');
    $check($price(1)['selling_price'] === '16.700', 'The candle reprices to (6.000 + 0.350 + 2.000) × 2 = 16.700.');
    $check($price(3)['selling_price'] === '36.400', 'The set containing it reprices too.');
    $check($price(4)['selling_price'] === '16.700', 'The linked diffuser follows its candle.');
    $check($price(5)['selling_price'] === '3.238', 'The event favor reprices: 1.850 × 1.75 = 3.2375 → 3.238.');
    $item = $db->query('SELECT unit_price_snapshot, line_total_snapshot FROM order_items WHERE order_id = 1')->fetch();
    $order = $db->query('SELECT subtotal, total_amount FROM orders WHERE id = 1')->fetch();
    $check($item['unit_price_snapshot'] === '14.700' && $order['total_amount'] === '14.700', 'The agreed order keeps its 14.700 price.');

    // 4. Adding a recipe prices the legacy product through the rules.
    $recipes = new ProductRecipeService(new ProductRecipeRepository($db), $pricing, $readiness);
    $check($recipes->addLine(7, 'ACTUAL', 1, '0.100')['success'] === true, 'A recipe line is added to the legacy product.');
    $check($price(7)['selling_price'] === '6.000' && $price(7)['price_rule'] === 'CANDLE_COST_X2' && $price(7)['pricing_issue'] === null, 'The legacy manual price is replaced by the calculated one.');
    $detail = $recipes->detail(3);
    // After the wax change the set costs 2 × 8.350 + 3.000 = 19.700.
    $check($detail['pricing']['rule'] === 'SET_COMPONENTS' && $detail['realCost'] === '19.700' && $detail['profit'] === '16.700',
        'Set profit uses its full component cost (36.400 - 19.700 = 16.700).');

    // 5. The database refuses an inconsistent pricing record.
    $rejected = false;
    try {
        $db->exec("UPDATE products SET price_calculated_at = NULL WHERE id = 1");
    } catch (PDOException) {
        $rejected = true;
    }
    $check($rejected, 'A rule without its calculation time is rejected by chk_products_price_calculation.');
} finally {
    // Always remove the throwaway database, success or failure.
    drop_throwaway_auth_schema($db, $databaseName);
}

// RESULT STEP: Report success only after every assertion above has passed.
echo "Pricing workflow checks passed.\n";
