<?php

declare(strict_types=1);



// WHAT: proves that accessory stock follows orders, using the real services
//       against a throwaway MySQL/MariaDB database
//       (tests/Support/throwaway-database.php).
// WHY:  accessory stock used to be a number on the product form that orders
//       never touched. Now AccessoryStockService checks availability with the
//       rows locked, deducts at PREPARED (including accessories inside sets),
//       keeps consumption when an order is moved to Trash, reconciles legacy returns on Restore, and ledgers every change
//       (product_stock_movements, migration 018).
require_once __DIR__ . '/../Support/throwaway-database.php';
require_once __DIR__ . '/../Support/TestCheck.php';

use App\Controllers\OrderTrashController;
use App\Database\TransactionManager;
use App\Repositories\AccessoryStockRepository;
use App\Repositories\AdminProductRepository;
use App\Repositories\MaterialStockRepository;
use App\Repositories\OrderProductionRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderStatusRepository;
use App\Repositories\ScentStockRepository;
use App\Repositories\PricingRepository;
use App\Repositories\TaxonomyRepository;
use App\Security\CsrfTokenManager;
use App\Services\AccessoryStockService;
use App\Services\AdminProductService;
use App\Services\ManualOrderService;
use App\Services\ScentAvailabilityService;
use App\Services\OrderEditService;
use App\Services\OrderProductionService;
use App\Services\OrderStatusService;
use App\Services\PricingService;
use App\Services\ProductImageStorage;
use App\Services\ProductReadinessService;

// DATABASE SETUP: A fresh database with every migration and seed.sql.
[$db, $databaseName] = create_throwaway_database('accessory');
$check = TestCheck::printing();

try {
    // Untracked cost materials (so only accessory stock moves in this test).
    $db->exec("INSERT INTO materials (id, material_type_id, sku, name, unit, stock_quantity, unit_cost, tracks_stock, is_active) VALUES
        (1, 1, 'WAX', 'Soy wax', 'kg', NULL, 25.000, 0, 1),
        (2, 4, 'BOX', 'Gift box purchase', 'piece', NULL, 3.000, 0, 1)");
    // 1 candle, 2 gift box accessory (5 in stock), 3 set = 1 candle + 2 gift boxes.
    $db->exec("INSERT INTO products (id, category_id, product_type_id, slug, stock_quantity, minimum_quantity, purchase_mode, is_active) VALUES
        (1, 1, 1, 'rose-candle', NULL, 1, 'CART', 1),
        (2, 6, 7, 'gift-box', 5, 1, 'CART', 1),
        (3, 2, NULL, 'gift-set', NULL, 1, 'CART', 1)");
    // Explicit IDs do not advance the sequence; later inserts must not reuse 1–3.
    $db->exec("SELECT setval('products_id_seq', (SELECT MAX(id)::bigint FROM products), true)");
    $db->exec("INSERT INTO product_translations (product_id, language_code, name, description) VALUES
        (1, 'en', 'Rose Candle', 'Handmade'), (2, 'en', 'Gift Box', 'Box'), (3, 'en', 'Gift Set', 'Set')");
    $db->exec("INSERT INTO product_materials (product_id, material_id, recipe_type, quantity_required) VALUES
        (1, 1, 'ACTUAL', 0.200), (2, 2, 'ACTUAL', 1.000)");
    $db->exec('INSERT INTO set_components (set_product_id, component_product_id, quantity) VALUES (3, 1, 1), (3, 2, 2)');
    $db->exec("INSERT INTO administrators_accounts (id, first_name, last_name, email, phone_number, password_hash, account_status)
        VALUES (1, 'Test', 'Administrator', 'admin@accessory.test', '20000001', 'not-a-real-hash', 'ACTIVE')");

    // The real services, wired as in app/Bootstrap/admin/*.php.
    $transactions = new TransactionManager($db);
    $pricing = new PricingService(new PricingRepository($db), $transactions);
    $pricing->recalculateAll();
    $orders = new OrderRepository($db);
    $productionRepository = new OrderProductionRepository($db);
    $production = new OrderProductionService($productionRepository, $transactions);
    $accessories = new AccessoryStockService(new AccessoryStockRepository($db), $transactions);
    $manual = new ManualOrderService(
        $orders,
        $transactions,
        $productionRepository,
        $production,
        $accessories,
        new ScentAvailabilityService(new ScentStockRepository($db), $transactions)
    );
    $statuses = new OrderStatusService(new OrderStatusRepository($db), new MaterialStockRepository($db, $transactions), $transactions, $production, $accessories);
    $_SESSION = [];
    $csrf = new CsrfTokenManager();
    $trash = new OrderTrashController($orders, $transactions, $csrf, $accessories);
    $stock = static fn (): int => (int) $db->query('SELECT stock_quantity FROM products WHERE id = 2')->fetchColumn();
    $statusOf = static fn (int $id): string => (string) $db->query("SELECT order_status FROM orders WHERE id = {$id}")->fetchColumn();
    $ledger = static fn (?int $orderId = null): array => $db->query('SELECT movement_type, quantity_change, stock_before, stock_after FROM product_stock_movements'
        . ($orderId === null ? '' : " WHERE order_id = {$orderId}") . ' ORDER BY id')->fetchAll();
    $orderCount = static fn (): int => (int) $db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $order = static fn (array $items): array => [
        'customer_name' => 'Accessory Customer',
        'customer_email' => '',
        'customer_phone' => '22123456',
        'delivery_method' => 'PICKUP',
        'required_date' => '2026-12-10',
        'delivery_fee' => '0.000',
        'street_address' => '',
        'delegation' => '',
        'governorate' => '',
        'postal_code' => '',
        'customer_notes' => '',
        'administrator_notes' => '',
        'customer_id' => '',
        'items' => $items,
    ];
    $line = static fn (int $productId, int $quantity): array => ['product_id' => (string) $productId, 'quantity' => (string) $quantity, 'unit_price' => '', 'customization_notes' => ''];
    $trashOrder = static function (int $id, bool $restore = false) use ($trash, $orders, $csrf): array {
        $post = ['id' => (string) $id, 'csrf_token' => $csrf->token()];
        if (!$restore) {
            $post['version'] = OrderEditService::version($orders->lockIncludingTrash($id));
        }

        return $trash->process($post, 1, $restore);
    };

    // 1. Availability counts accessories inside sets and what open orders have committed.
    $a = $manual->create(1, $order([$line(3, 2)]));
    $check($a['success'] === true && $stock() === 5, 'An order for 2 sets (4 gift boxes) is accepted; stock moves only at preparation.');
    $before = $orderCount();
    $b = $manual->create(1, $order([$line(1, 1), $line(2, 2)]));
    $check(
        !$b['success'] && str_contains($b['errors']['items'], 'Gift Box (2 requested, 1 available)') && $orderCount() === $before,
        'A second order cannot use boxes already committed to the first (5 − 4 = 1 available), and saves nothing.'
    );
    $b = $manual->create(1, $order([$line(1, 1), $line(2, 1)]));
    $check($b['success'] === true, 'An order within the remaining availability is accepted.');
    $refused = false;
    try {
        // The checkout path (CheckoutService) runs the same locked check.
        $accessories->assertAvailable([2 => 1]);
    } catch (DomainException) {
        $refused = true;
    }
    $check($refused, 'Checkout is refused once every unit is committed.');

    // 2. PREPARED deducts accessories exactly once, including those inside sets.
    $check($statuses->selectStatus($a['orderId'], 1, 'PREPARED', 'REQUESTED')['success'] && $stock() === 1, 'Preparing the set order takes 4 boxes (5 → 1).');
    $rows = $ledger($a['orderId']);
    $check(count($rows) === 1 && $rows[0]['movement_type'] === 'ORDER_DEDUCTION' && (int) $rows[0]['quantity_change'] === -4
        && (int) $rows[0]['stock_before'] === 5 && (int) $rows[0]['stock_after'] === 1, 'The deduction is ledgered with its before/after stock.');
    $check(
        $statuses->selectStatus($a['orderId'], 1, 'CONFIRMED', 'PREPARED')['success']
            && $statuses->selectStatus($a['orderId'], 1, 'PREPARED', 'CONFIRMED')['success'] && $stock() === 1 && count($ledger($a['orderId'])) === 1,
        'A status correction and re-preparation never deduct again.'
    );

    // 3. Cancelling an unprepared order frees its commitment; a held order is not double-counted.
    $check($trashOrder($b['orderId'])['success'] && $stock() === 1 && $ledger($b['orderId']) === [], 'Cancelling an unprepared order moves no stock.');
    $accessories->assertAvailable([2 => 1]);
    $check(true, 'Its commitment is released, and the prepared order already holding boxes is not counted twice (1 available).');

    // 4. Trash only hides an order. A prepared order keeps the boxes it consumed
    //    (documented rule since 2026-11-10: Trash does not return consumed
    //    accessory stock), and Restore does not take them a second time.
    $deductions = static fn (int $id): int => count(array_filter($ledger($id), static fn (array $row): bool => $row['movement_type'] === 'ORDER_DEDUCTION'));
    $cancelled = $trashOrder($a['orderId']);
    $check($cancelled['success'] && $stock() === 1 && count($ledger($a['orderId'])) === 1, 'Moving a prepared order to Trash keeps its 4 boxes consumed (stock stays 1, no new movement).');
    $check($trashOrder($a['orderId'], true)['success'] && $stock() === 1 && count($ledger($a['orderId'])) === 1, 'Restoring it from Trash does not deduct again (stock stays 1).');
    for ($cycle = 0; $cycle < 3; $cycle++) {
        $trashOrder($a['orderId']);
        $trashOrder($a['orderId'], true);
    }
    $check($stock() === 1 && $deductions($a['orderId']) === 1 && count($ledger($a['orderId'])) === 1, 'Three more Trash/Restore cycles neither lose nor duplicate stock (one deduction in the ledger).');

    // 5. A shortage at preparation rolls back the whole status change.
    $c = $manual->create(1, $order([$line(1, 1), $line(2, 1)]));
    $check($c['success'] === true, 'An order for the last box is accepted.');
    $db->exec('UPDATE products SET stock_quantity = 0 WHERE id = 2'); // e.g. the last box was damaged
    $failed = $statuses->selectStatus($c['orderId'], 1, 'PREPARED', 'REQUESTED');
    $check(!$failed['success'] && str_contains($failed['errors']['new_status'], 'Not enough accessory stock')
        && $statusOf($c['orderId']) === 'REQUESTED' && $ledger($c['orderId']) === [], 'Preparation without stock is refused and nothing changes.');
    $db->exec('UPDATE products SET stock_quantity = 1 WHERE id = 2');

    // 6. Legacy reconciliation: orders trashed under the old rule had their
    //    boxes returned (an ORDER_RESTORATION row). Restoring such an order
    //    takes back exactly what was returned, once, and only if it is there.
    $check($trashOrder($a['orderId'])['success'] && $stock() === 1, 'The prepared order is moved to Trash again.');
    $accessories->restoreForOrder($a['orderId'], 'PREPARED', 1); // what the old Trash action did
    $legacyRows = $ledger($a['orderId']);
    $check($stock() === 5 && end($legacyRows)['movement_type'] === 'ORDER_RESTORATION', 'Legacy fixture: the old rule returned 4 boxes (1 → 5).');
    $db->exec('UPDATE products SET stock_quantity = 2 WHERE id = 2'); // 3 boxes were used elsewhere meanwhile
    $refusedRestore = $trashOrder($a['orderId'], true);
    $check(!$refusedRestore['success'] && str_contains($refusedRestore['message'], 'Not enough accessory stock')
        && $orders->findSummary($a['orderId']) === null && $stock() === 2, 'Restoring a legacy order is refused when fewer than 4 boxes remain; the order stays in Trash.');
    $db->exec('UPDATE products SET stock_quantity = 5 WHERE id = 2');
    $check($trashOrder($a['orderId'], true)['success'] && $stock() === 1 && $deductions($a['orderId']) === 2, 'With stock available, the legacy order takes its 4 boxes back exactly once (5 → 1).');
    $trashOrder($a['orderId']);
    $trashOrder($a['orderId'], true);
    $check($stock() === 1 && $deductions($a['orderId']) === 2, 'A later Trash/Restore cycle of that order does not deduct again.');

    // 7. A handed-over order: the goods have left the shop, nothing moves.
    $check($statuses->selectStatus($a['orderId'], 1, 'PICKED_UP', 'PREPARED')['success'] && $stock() === 1, 'The order is handed over.');
    $check($trashOrder($a['orderId'])['success'] && $stock() === 1, 'Moving a picked-up order to Trash does not return its boxes.');
    $check($trashOrder($a['orderId'], true)['success'] && $stock() === 1 && $deductions($a['orderId']) === 2, 'Restoring a picked-up order does not deduct again, even with legacy history.');

    // 8. Manual stock edits are ledgered and cannot overwrite order movements.
    $db->exec('UPDATE products SET is_active = 0'); // edit drafts, so readiness rules do not interfere
    $readiness = new ProductReadinessService(new AdminProductRepository($db), $pricing, $transactions);
    $products = new AdminProductService(
        new AdminProductRepository($db),
        new TaxonomyRepository($db),
        $transactions,
        new ProductImageStorage(PRODUCT_IMAGE_PATH),
        $pricing,
        $readiness,
        $accessories
    );
    $core = ['category_id' => '6', 'product_type_id' => '7', 'slug' => 'gift-box', 'minimum_quantity' => '1', 'purchase_mode' => 'CART'];
    $saved = $products->saveCore(2, $core + ['stock_quantity' => '10', 'stock_quantity_original' => '1']);
    $rows = $ledger();
    $check($saved['success'] && $stock() === 10
        && end($rows)['movement_type'] === 'ADJUSTMENT' && (int) end($rows)['quantity_change'] === 9, 'A stock edit is saved as an ADJUSTMENT (+9).');
    $db->exec('UPDATE products SET stock_quantity = 7 WHERE id = 2'); // an order took 3 while the form was open
    $check(
        $products->saveCore(2, $core + ['stock_quantity' => '10', 'stock_quantity_original' => '10'])['success'] && $stock() === 7,
        'Saving the form without touching stock keeps the newer value.'
    );
    $stale = $products->saveCore(2, $core + ['stock_quantity' => '12', 'stock_quantity_original' => '10']);
    $check(!$stale['success'] && str_contains($stale['errors']['form'], 'changed') && $stock() === 7, 'A stale stock edit is refused.');
    $created = $products->saveCore(null, array_replace($core, ['slug' => 'ribbon']) + ['stock_quantity' => '8']);
    $opening = $db->query("SELECT movement_type, quantity_change FROM product_stock_movements WHERE product_id = {$created['id']}")->fetchAll();
    $check(
        $created['success'] && count($opening) === 1 && $opening[0]['movement_type'] === 'OPENING' && (int) $opening[0]['quantity_change'] === 8,
        'A new accessory records its opening stock.'
    );
} finally {
    // Always remove the throwaway database, success or failure.
    drop_throwaway_database($db, $databaseName);
}

// RESULT STEP: Report success only after every assertion above has passed.
echo "Accessory stock checks passed.\n";
