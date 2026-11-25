<?php

declare(strict_types=1);



// WHAT: proves that manual orders are connected to production data, using the
//       real services against a throwaway MySQL/MariaDB database
//       (tests/Support/throwaway-database.php).
// WHY:  manual order items used to be saved with product_id = NULL and no
//       option or cost snapshots, so the PREPARED deduction and the production
//       sheet could not tell which materials they consume. Now:
//         - catalogue items are linked, with agreed options, price and cost;
//         - custom items carry an explicit production recipe (migration 017);
//         - PREPARING/PREPARED are refused while any item lacks production data;
//         - custom recipes are frozen once the order is prepared.
require_once __DIR__ . '/../Support/throwaway-database.php';
require_once __DIR__ . '/../Support/TestCheck.php';

use App\Controllers\OrderController;
use App\Database\TransactionManager;
use App\Repositories\AccessoryStockRepository;
use App\Repositories\MaterialStockRepository;
use App\Repositories\OrderProductionRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderStatusRepository;
use App\Repositories\ScentStockRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductionRepository;
use App\Security\CsrfTokenManager;
use App\Services\AccessoryStockService;
use App\Services\ManualOrderService;
use App\Services\ScentAvailabilityService;
use App\Services\OrderProductionService;
use App\Services\OrderService;
use App\Services\OrderStatusService;
use App\Services\PricingService;
use App\Services\ProductionService;

// DATABASE SETUP: A fresh database with every migration and seed.sql.
[$db, $databaseName] = create_throwaway_database('manualorder');
$check = TestCheck::printing();

try {
    // Reference data: tracked wax (10 kg at 25.000) and wicks (100 at 0.350),
    // an inactive material, one color and one scent with English names.
    $db->exec("INSERT INTO materials (id, material_type_id, sku, name, unit, stock_quantity, unit_cost, tracks_stock, is_active) VALUES
        (1, 1, 'WAX', 'Soy wax', 'kg', 10.000, 25.000, 1, 1),
        (2, 1, 'WICK', 'Wick', 'piece', 100.000, 0.350, 1, 1),
        (3, 1, 'OLD', 'Retired dye', 'g', NULL, 1.000, 0, 0)");
    $db->exec("INSERT INTO colors (id, slug, hex_code, is_active) VALUES (1, 'ivory', '#FFFFF0', 1)");
    $db->exec("INSERT INTO color_translations (color_id, language_code, name) VALUES (1, 'en', 'Ivory')");
    $db->exec("INSERT INTO scents (id, slug, is_active) VALUES (1, 'vanilla', 1)");
    $db->exec("INSERT INTO scent_translations (scent_id, language_code, name) VALUES (1, 'en', 'Vanilla')");
    // Product 1: an active candle with a recipe and options; product 2: a draft.
    $db->exec("INSERT INTO products (id, category_id, product_type_id, slug, minimum_quantity, purchase_mode, is_active) VALUES
        (1, 1, 1, 'rose-candle', 1, 'CART', 1),
        (2, 1, 1, 'draft-candle', 1, 'CART', 0)");
    $db->exec("INSERT INTO product_translations (product_id, language_code, name, description) VALUES
        (1, 'en', 'Rose Candle', 'Handmade'), (2, 'en', 'Draft Candle', 'Handmade')");
    $db->exec("INSERT INTO product_colors (product_id, color_id, image_path) VALUES (1, 1, 'assets/products/rose-ivory.jpg')");
    $db->exec('INSERT INTO product_scents (product_id, scent_id) VALUES (1, 1)');
    $db->exec("INSERT INTO product_materials (product_id, material_id, recipe_type, quantity_required) VALUES
        (1, 1, 'ACTUAL', 0.200), (1, 2, 'ACTUAL', 1.000), (2, 1, 'ACTUAL', 0.100)");
    $db->exec("INSERT INTO administrators_accounts (id, first_name, last_name, email, phone_number, password_hash, account_status)
        VALUES (1, 'Test', 'Administrator', 'admin@manual-order.test', '20000000', 'not-a-real-hash', 'ACTIVE')");

    // The real services, wired as in app/Bootstrap/admin/admin-orders.php.
    $transactions = new TransactionManager($db);
    (new PricingService(new PricingRepository($db), $transactions))->recalculateAll();
    // Candles are priced by the owner: price the fixture candle before it is sold.
    (new \App\Services\CandlePriceService(new \App\Repositories\CandlePricingRepository($db), $transactions))->setManualPrice(1, '12.000', null);
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
    $statusRepository = new OrderStatusRepository($db);
    $statuses = new OrderStatusService($statusRepository, new MaterialStockRepository($db, $transactions), $transactions, $production, $accessories);
    $stockOf = static fn (int $id): string => (string) $db->query("SELECT stock_quantity FROM materials WHERE id = {$id}")->fetchColumn();
    $statusOf = static fn (int $id): string => (string) $db->query("SELECT order_status FROM orders WHERE id = {$id}")->fetchColumn();
    $orderCount = static fn (): int => (int) $db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $order = static fn (array $items): array => [
        'customer_name' => 'Manual Customer', 'customer_email' => '', 'customer_phone' => '22123456',
        'delivery_method' => 'PICKUP', 'required_date' => '2026-12-10', 'delivery_fee' => '0.000',
        'street_address' => '', 'delegation' => '', 'governorate' => '', 'postal_code' => '',
        'customer_notes' => '', 'administrator_notes' => '', 'customer_id' => '', 'items' => $items,
    ];
    $catalogueItem = ['product_id' => '1', 'color_id' => '1', 'scent_id' => '1', 'product_name' => '', 'quantity' => '2', 'unit_price' => '', 'customization_notes' => ''];
    $customItem = ['product_id' => '', 'product_name' => 'Wedding bouquet', 'quantity' => '1', 'unit_price' => '30.000', 'customization_notes' => 'Blush ribbon',
        'recipe' => [['material_id' => '1', 'quantity' => '0.500'], ['material_id' => '2', 'quantity' => '3'], ['material_id' => '', 'quantity' => '']]];

    // 1. The form offers only active products (with their active options) and active materials.
    $options = $manual->formOptions();
    $check(isset($options['products'][1]) && !isset($options['products'][2]) && $options['products'][1]['colors'] === [1 => 'Ivory']
        && isset($options['materials'][1]) && !isset($options['materials'][3]), 'The form offers active products, their options, and active materials.');

    // 2. A catalogue item and a custom item are saved with links, options, price, cost and recipe.
    $created = $manual->create(1, $order([$catalogueItem, $customItem]));
    $check($created['success'] === true, 'A manual order with a catalogue item and a custom item is created.');
    $items = $db->query("SELECT * FROM order_items WHERE order_id = {$created['orderId']} ORDER BY id")->fetchAll();
    $check(
        (int) $items[0]['product_id'] === 1 && (int) $items[0]['selected_color_id'] === 1 && $items[0]['color_name_snapshot'] === 'Ivory'
        && (int) $items[0]['selected_scent_id'] === 1 && $items[0]['scent_name_snapshot'] === 'Vanilla' && $items[0]['product_name_snapshot'] === 'Rose Candle',
        'The catalogue item is linked to its product and captures the agreed color and scent.'
    );
    $check(
        $items[0]['unit_price_snapshot'] === '12.000' && $items[0]['unit_cost_snapshot'] === '5.350' && $items[0]['line_total_snapshot'] === '24.000',
        'A blank price uses the owner\'s candle price (12.000); the production cost is frozen (5.350).'
    );
    $check(
        $items[1]['product_id'] === null && $items[1]['unit_price_snapshot'] === '30.000' && $items[1]['unit_cost_snapshot'] === '13.550',
        'The custom item keeps its agreed price and its recipe cost (0.5 × 25 + 3 × 0.35 = 13.550).'
    );
    $lines = $db->query("SELECT material_id, material_name_snapshot, unit_snapshot, unit_cost_snapshot, quantity_required
        FROM order_item_materials WHERE order_item_id = {$items[1]['id']} ORDER BY material_id")->fetchAll();
    $check(
        count($lines) === 2 && $lines[0]['material_name_snapshot'] === 'Soy wax' && $lines[0]['unit_cost_snapshot'] === '25.000'
        && $lines[0]['quantity_required'] === '0.500' && $lines[1]['quantity_required'] === '3.000',
        'The custom recipe is stored with frozen material names, units and costs; blank rows are ignored.'
    );
    $total = $db->query("SELECT subtotal, total_amount FROM orders WHERE id = {$created['orderId']}")->fetch();
    $check($total['subtotal'] === '54.000' && $total['total_amount'] === '54.000', 'Order totals use the owner\'s candle price (24.000) plus the custom item (30.000).');

    // 3. Invalid production data is refused, and nothing is saved.
    $before = $orderCount();
    $invalid = [
        'an unavailable color' => array_replace($catalogueItem, ['color_id' => '9']),
        'a missing scent' => array_replace($catalogueItem, ['scent_id' => '']),
        'a draft product' => array_replace($catalogueItem, ['product_id' => '2']),
        'a custom item without recipe' => array_replace($customItem, ['recipe' => []]),
        'an inactive material' => array_replace($customItem, ['recipe' => [['material_id' => '3', 'quantity' => '1']]]),
        'a repeated material' => array_replace($customItem, ['recipe' => [['material_id' => '1', 'quantity' => '1'], ['material_id' => '1', 'quantity' => '2']]]),
        'a zero recipe quantity' => array_replace($customItem, ['recipe' => [['material_id' => '1', 'quantity' => '0']]]),
    ];
    foreach ($invalid as $label => $item) {
        $result = $manual->create(1, $order([$item]));
        $check(!$result['success'] && isset($result['errors']['items_0']), "An item with {$label} is rejected.");
    }
    $check($orderCount() === $before, 'Rejected manual orders leave no rows behind.');

    // 4. A legacy manual item (no product, no recipe) blocks preparation.
    $db->exec("INSERT INTO orders (id, order_number, created_by_administrator_id, language_code, order_source, order_status, delivery_method, required_date,
            customer_name_snapshot, customer_phone_snapshot, subtotal, delivery_fee, total_amount, required_deposit_amount, price_locked_at)
        VALUES (900, 'ORD-M-LEGACY-0001', 1, 'en', 'MANUAL', 'REQUESTED', 'PICKUP', '2026-12-11', 'Legacy Customer', '22123457', 20.000, 0, 20.000, 0, NOW())");
    $legacyItemId = $orders->insertManualOrderItem(['order_id' => 900, 'product_name' => 'Legacy lantern', 'quantity' => 1,
        'unit_price' => '20.000', 'line_total' => '20.000', 'customization_notes' => null]);
    $check($statuses->transition(900, 1, 'CONFIRMED', null)['success'], 'An order with missing production data can still be confirmed.');
    $blocked = $statuses->transition(900, 1, 'PREPARING', null);
    $check(
        !$blocked['success'] && str_contains($blocked['errors']['transition'], 'Legacy lantern')
        && str_contains($blocked['errors']['transition'], 'custom production recipe') && $statusOf(900) === 'CONFIRMED',
        'PREPARING is refused while an item has no catalogue product and no custom recipe.'
    );
    $jump = $statuses->selectStatus(900, 1, 'PREPARED', 'CONFIRMED');
    $check(!$jump['success'] && $statusOf(900) === 'CONFIRMED', 'Jumping straight to PREPARED is refused too, and rolls back.');
    $page = $production->orderProduction(900, 'CONFIRMED');
    $check(
        $page['items'][$legacyItemId]['source'] === 'MISSING' && $page['missingCount'] === 1 && $page['editable'],
        'The order page reports the missing item and offers the recipe form.'
    );

    // 5. Adding a custom recipe on the order page unblocks it; the PREPARED deduction uses it.
    $noLines = $production->saveItemRecipe(900, $legacyItemId, []);
    $check(!$noLines['success'], 'An unlinked item cannot be left without recipe lines.');
    $saved = $production->saveItemRecipe(900, $legacyItemId, [['material_id' => '1', 'quantity' => '1.000']]);
    $cost = $db->query("SELECT unit_cost_snapshot FROM order_items WHERE id = {$legacyItemId}")->fetchColumn();
    $check($saved['success'] && $cost === '25.000', 'Saving a custom recipe records the item cost (1 × 25.000).');
    $check($production->orderProduction(900, 'CONFIRMED')['items'][$legacyItemId]['source'] === 'CUSTOM', 'The item is now produced from its custom recipe.');
    $check(
        $statuses->selectStatus(900, 1, 'PREPARED', 'CONFIRMED')['success'] && $stockOf(1) === '9.000',
        'A price-locked manual order now deducts its materials at PREPARED (10 - 1 = 9 kg).'
    );

    // 6. The new manual order deducts both the catalogue and the custom recipe.
    $check($statuses->selectStatus($created['orderId'], 1, 'PREPARED', 'REQUESTED')['success'], 'The manual order advances to PREPARED.');
    // Wax: 2 × 0.200 + 1 × 0.500 = 0.900; wicks: 2 × 1 + 1 × 3 = 5.
    $check($stockOf(1) === '8.100' && $stockOf(2) === '95.000', 'Catalogue and custom recipes are both deducted exactly.');

    // 7. Once prepared, the recipe is frozen by the service and by the database.
    $frozen = $production->saveItemRecipe(900, $legacyItemId, [['material_id' => '1', 'quantity' => '2.000']]);
    $check(!$frozen['success'] && str_contains($frozen['errors']['recipe'], 'prepared'), 'The service refuses to change a prepared order\'s recipe.');
    $triggerRejected = false;
    try {
        $db->exec("UPDATE order_item_materials SET quantity_required = 2.000 WHERE order_item_id = {$legacyItemId}");
    } catch (PDOException) {
        $triggerRejected = true;
    }
    $check($triggerRejected, 'Migration 017 triggers refuse direct SQL edits of a prepared recipe.');
    $check($statuses->selectStatus(900, 1, 'PREPARING', 'PREPARED')['success'] && $statuses->selectStatus(900, 1, 'PREPARED', 'PREPARING')['success']
        && $stockOf(1) === '8.100', 'Re-preparing after a status correction never deducts again.');

    // 8. Normal order entry has no recipe input (documented in
    //    order-entry-and-custom-products-implementation.md): a recipe posted
    //    with a catalogue line is ignored and the catalogue recipe is frozen.
    //    Custom recipes belong to custom lines (e.g. converted requests).
    $override = $manual->create(1, $order([array_replace($catalogueItem, ['quantity' => '4', 'recipe' => [['material_id' => '1', 'quantity' => '0.050']]])]));
    $overrideItemId = $override['success'] ? (int) $db->query("SELECT id FROM order_items WHERE order_id = {$override['orderId']}")->fetchColumn() : 0;
    $overrideItem = $db->query("SELECT product_id, unit_cost_snapshot FROM order_items WHERE order_id = {$override['orderId']}")->fetch();
    $check(
        $override['success'] && (int) $overrideItem['product_id'] === 1 && $overrideItem['unit_cost_snapshot'] === '5.350'
        && count($statusRepository->findMaterialRequirements($override['orderId'])) === 2,
        'A recipe posted with a catalogue line is ignored; the catalogue recipe and cost are frozen.'
    );
    $refusedChange = $production->saveItemRecipe($override['orderId'], $overrideItemId, [['material_id' => '1', 'quantity' => '0.050']]);
    $refusedClear = $production->saveItemRecipe($override['orderId'], $overrideItemId, []);
    $overrideItem = $db->query("SELECT unit_cost_snapshot FROM order_items WHERE order_id = {$override['orderId']}")->fetch();
    $check(
        !$refusedChange['success'] && str_contains($refusedChange['errors']['recipe'] ?? '', 'captured at sale cannot be changed')
        && !$refusedClear['success'] && $overrideItem['unit_cost_snapshot'] === '5.350'
        && count($statusRepository->findMaterialRequirements($override['orderId'])) === 2,
        'The catalogue recipe captured at sale cannot be replaced or cleared later; cost and materials stay as sold.'
    );

    // 9. The production sheet includes custom items and their materials.
    $sheetOrder = $manual->create(1, array_replace($order([$customItem]), ['required_date' => '2026-12-20']));
    $statuses->transition($sheetOrder['orderId'], 1, 'CONFIRMED', null);
    $db->exec("INSERT INTO orders (id, order_number, language_code, order_source, order_status, delivery_method, required_date,
            customer_name_snapshot, customer_phone_snapshot, subtotal, delivery_fee, total_amount, required_deposit_amount)
        VALUES (5000, 'ORD-M-LEGACY-0002', 'en', 'MANUAL', 'CONFIRMED', 'PICKUP', '2026-12-20', 'Orphan Customer', '22123458', 5.000, 0, 5.000, 0)");
    $orders->insertManualOrderItem(['order_id' => 5000, 'product_name' => 'Orphan item', 'quantity' => 1, 'unit_price' => '5.000', 'line_total' => '5.000', 'customization_notes' => null]);
    $sheet = (new ProductionService(new ProductionRepository($db)))->sheet(['date_from' => '2026-12-20', 'date_to' => '2026-12-20'], new DateTimeImmutable('2026-12-01'));
    $custom = array_values(array_filter($sheet['toProduce'], static fn (array $group): bool => $group['custom_recipe']));
    $wax = array_values(array_filter($sheet['materials'], static fn (array $material): bool => $material['material_id'] === 1));
    $check(
        count($custom) === 1 && $custom[0]['product_name'] === 'Wedding bouquet' && $custom[0]['quantity'] === 1,
        'The production sheet lists the custom item under its own name.'
    );
    $check($wax !== [] && $wax[0]['required_quantity'] === '0.500', 'The sheet counts the custom recipe\'s materials.');
    $check($sheet['orphanedItemCount'] === 1, 'An item with neither product nor recipe is still reported as excluded.');

    // 10. The order page renders the production panel with its recipe form.
    //     (Views share this scope and set their own $order, so prepare the form values first.)
    $createValues = $order([$catalogueItem]);
    define('BASE_URL', '/homas-candles/public/');
    $_SESSION = [];
    $csrf = new CsrfTokenManager();
    $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $orderDetail = (new OrderController(new OrderService($orders)))->show(['id' => '5000']);
    $orderProduction = $production->orderProduction(5000, 'CONFIRMED');
    $orderStatusOptions = [];
    $backUrl = BASE_URL . 'admin/orders/index.php';
    ob_start();
    require VIEW_PATH . '/Pages/admin/orders/detail.php';
    $html = (string) ob_get_clean();
    $check(
        str_contains($html, 'Preparation is blocked') && str_contains($html, 'admin/orders/item-recipe.php')
        && str_contains($html, 'name="recipe[0][material_id]"') && str_contains($html, 'Soy wax (kg)'),
        'The order page explains the block and offers a CSRF-protected recipe form.'
    );

    $manualOrderFlash = ['errors' => [], 'values' => [], 'success' => null];
    $manualOrderValues = $createValues;
    $manualOrderOptions = $manual->formOptions();
    ob_start();
    require VIEW_PATH . '/Pages/admin/orders/create.php';
    $html = (string) ob_get_clean();
    $check(
        str_contains($html, 'name="items[0][product_id]"') && str_contains($html, '<option value="1" selected>Ivory</option>')
        // One-off designs are recorded as Custom Requests (catalogue-only entry).
        && str_contains($html, 'id="manual-order-catalogue"') && !str_contains($html, 'Custom item (explicit production recipe)')
        && str_contains($html, 'admin/requests/create.php'),
        'The manual-order form offers catalogue products and their options, and sends one-off designs to Custom Requests.'
    );
} finally {
    // Always remove the throwaway database, success or failure.
    drop_throwaway_database($db, $databaseName);
}

// RESULT STEP: Report success only after every assertion above has passed.
echo "Manual order production checks passed.\n";
