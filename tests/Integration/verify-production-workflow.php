<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Integration verification for Verify Production Workflow.
 *
 * DATA FLOW: This executable test crosses application and persistence boundaries to confirm the feature layers work together.
 */

require_once __DIR__ . '/../../app/config/paths.php';
ini_set('session.save_path', TEST_SESSION_PATH);
// SETUP STEP: Load the application code or delegated test required before this command can run.
require_once __DIR__ . '/../../app/Bootstrap/admin/admin-production.php';
// SETUP STEP: Load the application code or delegated test required before this command can run.
require_once __DIR__ . '/../../app/Bootstrap/admin/admin-catalogue.php';
// SETUP STEP: Load the application code or delegated test required before this command can run.
require_once __DIR__ . '/../../app/Bootstrap/admin/admin-inventory.php';

$results = [];
$check = static function (bool $condition, string $message) use (&$results): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $results[] = '[PASS] ' . $message;
};

$suffix = substr((string) hrtime(true), -8);
$administratorId = null;
$orderIds = [];
$materialIds = [];
$productIds = [];

$today = new DateTimeImmutable('today');
$dateYesterday = $today->modify('-1 day')->format('Y-m-d');
$dateToday = $today->format('Y-m-d');
$dateSoon = $today->modify('+3 days')->format('Y-m-d');
$dateFuture = $today->modify('+10 days')->format('Y-m-d');

$insertOrder = static function (string $number, string $status, string $requiredDate) use ($databaseConnection): int {
    $statement = $databaseConnection->prepare(
        "INSERT INTO orders
            (order_number, language_code, order_source, order_status, delivery_method, required_date,
             customer_name_snapshot, customer_phone_snapshot, subtotal, delivery_fee, total_amount, required_deposit_amount)
         VALUES
            (:order_number, 'en', 'MANUAL', :status, 'PICKUP', :required_date,
             'Production Verifier Customer', '20123456', 10.000, 0.000, 10.000, 0.000)"
    );
    $statement->execute(['order_number' => $number, 'status' => $status, 'required_date' => $requiredDate]);

    return (int) $databaseConnection->lastInsertId();
};

$insertItem = static function (int $orderId, ?int $productId, string $name, int $quantity, ?string $notes = null) use ($databaseConnection): void {
    $statement = $databaseConnection->prepare(
        "INSERT INTO order_items
            (order_id, product_id, product_name_snapshot, quantity, unit_price_snapshot, line_total_snapshot, customization_notes)
         VALUES
            (:order_id, :product_id, :name, :quantity, 1.000, :line_total, :notes)"
    );
    $statement->execute([
        'order_id' => $orderId, 'product_id' => $productId, 'name' => $name,
        'quantity' => $quantity, 'line_total' => number_format($quantity, 3, '.', ''), 'notes' => $notes,
    ]);
};

$findByProduct = static function (array $groups, int $productId): array {
    return array_values(array_filter($groups, static fn (array $group): bool => $group['product_id'] === $productId));
};
$findMaterial = static function (array $materials, int $materialId): ?array {
    foreach ($materials as $material) {
        if ($material['material_id'] === $materialId) {
            return $material;
        }
    }
    return null;
};

try {
    $administrator = $databaseConnection->prepare(
        'INSERT INTO administrators_accounts
            (first_name, last_name, email, phone_number, password_hash, account_status)
         VALUES (:first_name, :last_name, :email, :phone, :password_hash, \'ACTIVE\')'
    );
    $administrator->execute([
        'first_name' => 'Production', 'last_name' => 'Verifier',
        'email' => "production-{$suffix}@example.test", 'phone' => '7' . substr($suffix, -7),
        'password_hash' => password_hash('ProductionVerifier123!', PASSWORD_DEFAULT),
    ]);
    $administratorId = (int) $databaseConnection->lastInsertId();

    // === Fixture materials ===
    $materialA = $materialService->save(null, [
        'material_type_id' => '1', 'sku' => "P08-WAX-{$suffix}", 'name' => "Test Wax {$suffix}", 'unit' => 'g',
        'unit_cost' => '1.000', 'tracks_stock' => '1', 'minimum_stock_level' => '1.000', 'initial_stock_quantity' => '5.000', 'is_active' => '1',
    ]);
    $check($materialA['success'], 'A tracked, consumable material can be created for the feasibility check.');
    $materialAId = $materialA['id'];
    $materialIds[] = $materialAId;

    $materialC = $materialService->save(null, [
        'material_type_id' => '1', 'sku' => "P08-LBR-{$suffix}", 'name' => "Test Labor {$suffix}", 'unit' => 'unit',
        'unit_cost' => '0.000', 'tracks_stock' => '0', 'is_active' => '1',
    ]);
    $check($materialC['success'], 'A non-stock-tracked material can be created for the exclusion check.');
    $materialCId = $materialC['id'];
    $materialIds[] = $materialCId;

    // === Fixture products: a leaf product with a recipe, a leaf product with no recipe, and a set ===
    $candleA = $adminProductService->saveCore(null, [
        'category_id' => '1', 'product_type_id' => '3', 'slug' => "p08-candle-a-{$suffix}",
        'selling_price' => '20.000', 'purchase_mode' => 'CART', 'minimum_quantity' => '1',
    ]);
    $check($candleA['success'], 'A leaf product with a recipe can be created.');
    $candleAId = $candleA['id'];
    $productIds[] = $candleAId;

    $candleB = $adminProductService->saveCore(null, [
        'category_id' => '1', 'product_type_id' => '3', 'slug' => "p08-candle-b-{$suffix}",
        'selling_price' => '15.000', 'purchase_mode' => 'CART', 'minimum_quantity' => '1',
    ]);
    $check($candleB['success'], 'A leaf product with no recipe can be created.');
    $candleBId = $candleB['id'];
    $productIds[] = $candleBId;

    $setStatement = $databaseConnection->prepare(
        "INSERT INTO products (slug, purchase_mode, minimum_quantity, is_made_to_order, can_purchase_alone, is_active)
         VALUES (:slug, 'CART', 1, 0, 1, 0)"
    );
    $setStatement->execute(['slug' => "p08-set-x-{$suffix}"]);
    $setXId = (int) $databaseConnection->lastInsertId();
    $productIds[] = $setXId;

    $databaseConnection->prepare(
        'INSERT INTO set_components (set_product_id, component_product_id, quantity) VALUES (:set_id, :component_id, :qty)'
    )->execute(['set_id' => $setXId, 'component_id' => $candleAId, 'qty' => 2]);
    $databaseConnection->prepare(
        'INSERT INTO set_components (set_product_id, component_product_id, quantity) VALUES (:set_id, :component_id, :qty)'
    )->execute(['set_id' => $setXId, 'component_id' => $candleBId, 'qty' => 1]);

    $addRecipeA = $productRecipeService->addLine($candleAId, 'ACTUAL', $materialAId, '2.000');
    $check($addRecipeA['success'], 'The tracked material can be added to the recipe product.');
    $addRecipeC = $productRecipeService->addLine($candleAId, 'ACTUAL', $materialCId, '1.000');
    $check($addRecipeC['success'], 'The non-tracked material can also be added to the recipe product.');

    // === Fixture orders across statuses and required dates ===
    $orderOverdue = $insertOrder("P08-{$suffix}-1", 'CONFIRMED', $dateYesterday);
    $orderIds[] = $orderOverdue;
    $insertItem($orderOverdue, $candleAId, 'Candle A', 3);

    $orderToday = $insertOrder("P08-{$suffix}-2", 'PREPARING', $dateToday);
    $orderIds[] = $orderToday;
    $insertItem($orderToday, $setXId, 'Set X', 2);

    $orderSoon = $insertOrder("P08-{$suffix}-3", 'CONFIRMED', $dateSoon);
    $orderIds[] = $orderSoon;
    $insertItem($orderSoon, $candleBId, 'Candle B', 1);

    $orderFuture = $insertOrder("P08-{$suffix}-4", 'CONFIRMED', $dateFuture);
    $orderIds[] = $orderFuture;
    $insertItem($orderFuture, $candleAId, 'Candle A', 1, 'Extra glitter');
    $insertItem($orderFuture, null, 'Removed product', 1);

    $orderOutOfScopeRequested = $insertOrder("P08-{$suffix}-5", 'REQUESTED', $dateToday);
    $orderIds[] = $orderOutOfScopeRequested;
    $insertItem($orderOutOfScopeRequested, $candleAId, 'Candle A', 100);

    $orderOutOfScopeDelivered = $insertOrder("P08-{$suffix}-6", 'DELIVERED', $dateToday);
    $orderIds[] = $orderOutOfScopeDelivered;
    $insertItem($orderOutOfScopeDelivered, $candleAId, 'Candle A', 100);

    // === Queue: status inclusion, date-boundary timing, and priority sort ===
    $queueResult = $productionService->queue(['q' => $suffix, 'sort' => 'priority', 'direction' => 'asc'], $today);
    $queueNumbers = array_map(static fn (array $row): string => (string) $row['order_number'], $queueResult['orders']);
    $check(
        $queueNumbers === ["P08-{$suffix}-1", "P08-{$suffix}-2", "P08-{$suffix}-3", "P08-{$suffix}-4"],
        'Only confirmed/preparing orders appear in the queue, sorted overdue-first then by required date.'
    );

    $timingByNumber = [];
    foreach ($queueResult['orders'] as $row) {
        $timingByNumber[$row['order_number']] = $row['timing']['label'];
    }
    $check($timingByNumber["P08-{$suffix}-1"] === 'Overdue', 'A required date before today is labelled overdue.');
    $check($timingByNumber["P08-{$suffix}-2"] === 'Due today', 'A required date equal to today is labelled due today.');
    $check($timingByNumber["P08-{$suffix}-3"] === 'Due soon', 'A required date a few days out is labelled due soon.');
    $check($timingByNumber["P08-{$suffix}-4"] === 'Scheduled', 'A required date far in the future is labelled scheduled.');

    $descResult = $productionService->queue(['q' => $suffix, 'sort' => 'priority', 'direction' => 'desc'], $today);
    $descNumbers = array_map(static fn (array $row): string => (string) $row['order_number'], $descResult['orders']);
    $check(
        $descNumbers === ["P08-{$suffix}-4", "P08-{$suffix}-3", "P08-{$suffix}-2", "P08-{$suffix}-1"],
        'Reversing the sort direction reverses the overdue-first ordering.'
    );

    $confirmedOnly = $productionService->queue(['q' => $suffix, 'status' => 'CONFIRMED'], $today);
    $confirmedNumbers = array_map(static fn (array $row): string => (string) $row['order_number'], $confirmedOnly['orders']);
    $check(
        !in_array("P08-{$suffix}-2", $confirmedNumbers, true) && count($confirmedNumbers) === 3,
        'The status filter narrows the queue within the allowed queue statuses.'
    );

    // === Sheet: aggregation without double counting, sets, customization grouping ===
    $sheet = $productionService->sheet([
        'product' => (string) $candleAId, 'date_from' => $dateYesterday, 'date_to' => $dateFuture,
    ], $today);

    $candleAGroups = $findByProduct($sheet['toProduce'], $candleAId);
    $candleAQuantity = array_sum(array_map(static fn (array $group): int => $group['quantity'], $candleAGroups));
    $check($candleAQuantity === 8, 'Produce quantities aggregate 3 (direct) + 4 (2 sets x 2 components) + 1 (direct) without double counting.');
    $check(count($candleAGroups) === 2, 'Distinct customization notes are kept as separate groups instead of being merged.');

    $setGroups = $findByProduct($sheet['toProduce'], $setXId);
    $check($setGroups === [], 'A set product itself is never counted as something to produce.');

    $assembleGroups = $findByProduct($sheet['toAssemble'], $setXId);
    $check(count($assembleGroups) === 1 && $assembleGroups[0]['quantity'] === 2, 'A set is listed once to assemble, at its own order quantity.');

    $check($sheet['orphanedItemCount'] === 1, 'An order item referencing a removed product is counted as orphaned within the scoped window.');

    // === Sheet: missing recipe reporting ===
    $sheetForB = $productionService->sheet([
        'product' => (string) $candleBId, 'date_from' => $dateToday, 'date_to' => $dateFuture,
    ], $today);
    $check(
        in_array($candleBId, $sheetForB['missingRecipeProductIds'], true),
        'A product with no actual recipe is explicitly reported as missing recipe data.'
    );
    $candleBGroups = $findByProduct($sheetForB['toProduce'], $candleBId);
    $check($candleBGroups !== [], 'A product with missing recipe data is still listed as something to produce.');

    // === Sheet: material feasibility ===
    $materialEntry = $findMaterial($sheet['materials'], $materialAId);
    $check($materialEntry !== null, 'A tracked, consumable material used by an in-scope recipe appears in the feasibility list.');
    $check($materialEntry['required_quantity'] === '16.000', 'Required quantity is derived from actual recipe inputs across all in-scope orders (8 x 2.000).');
    $check($materialEntry['stock_quantity'] === '5.000', 'Feasibility compares against the current tracked stock quantity.');
    $check($materialEntry['shortfall'] === '11.000', 'A shortfall is reported when required quantity exceeds tracked stock.');
    $check($materialEntry['unit'] === 'g', 'Feasibility reports the material\'s correct unit.');
    $check($findMaterial($sheet['materials'], $materialCId) === null, 'A non-tracked material is excluded from the feasibility list.');

    $shortageIds = array_map(static fn (array $material): int => $material['material_id'], $sheet['shortages']);
    $check(in_array($materialAId, $shortageIds, true), 'The material shortage list includes the short material.');

    foreach ($sheet['orders'] as $order) {
        $check(!array_key_exists('customer_email_snapshot', $order), 'Sheet order rows never expose customer contact fields.');
        $check(!array_key_exists('customer_phone_snapshot', $order), 'Sheet order rows never expose customer phone fields.');
    }

    // === Sheet: orphaned item is reported when its date is in scope ===
    $futureOnlySheet = $productionService->sheet([
        'product' => (string) $candleAId, 'date_from' => $dateFuture, 'date_to' => $dateFuture,
    ], $today);
    $check($futureOnlySheet['orphanedItemCount'] === 1, 'An order item pointing at a removed product is reported, not silently dropped.');

    // === Sheet: empty period renders safely ===
    $emptyFrom = $today->modify('+400 days')->format('Y-m-d');
    $emptyTo = $today->modify('+401 days')->format('Y-m-d');
    $emptySheet = $productionService->sheet(['date_from' => $emptyFrom, 'date_to' => $emptyTo], $today);
    $check(
        $emptySheet['orderCount'] === 0 && $emptySheet['toProduce'] === [] && $emptySheet['materials'] === [],
        'An empty schedule window renders safely with no orders, production lines, or material rows.'
    );

    // === Calendar: date-scoped counts and injection-safe navigation ===
    $calendar = $productionService->calendar(['product' => (string) $candleAId, 'month' => $today->format('Y-m')], $today);
    $countsByDate = [];
    foreach ($calendar['weeks'] as $week) {
        foreach ($week as $day) {
            $countsByDate[$day['date']] = $day;
        }
    }
    $check(($countsByDate[$dateYesterday]['count'] ?? 0) >= 1, 'The calendar counts an overdue day that has an in-scope order.');
    $check(($countsByDate[$dateYesterday]['isOverdue'] ?? false) === true, 'A past day with orders is flagged overdue on the calendar.');
    $check(($countsByDate[$dateToday]['count'] ?? 0) >= 1, 'The calendar counts today when it has an in-scope order.');
    $check(($countsByDate[$dateToday]['isOverdue'] ?? true) === false, 'Today is never flagged overdue on the calendar.');

    $unsafeCalendar = $productionService->calendar([
        'month' => "2026-01' OR '1'='1", 'status' => "CONFIRMED' OR '1'='1", 'product' => 'not-a-number',
    ], $today);
    $check(
        $unsafeCalendar['filtersAdjusted'] === true && $unsafeCalendar['month'] === $today->format('Y-m') && $unsafeCalendar['weeks'] !== [],
        'An invalid month or filter value falls back safely instead of reaching SQL.'
    );

    // === Injection-safe / allow-listed queue filters ===
    $unsafeQueue = $productionService->queue([
        'status' => "CONFIRMED' OR '1'='1", 'sort' => "priority; DROP TABLE orders", 'product' => 'abc',
        'date_from' => '2026-99-99', 'direction' => 'sideways',
    ], $today);
    $check(
        $unsafeQueue['filters']['status'] === null
        && $unsafeQueue['sort'] === 'priority'
        && $unsafeQueue['filters']['product_id'] === null
        && $unsafeQueue['filters']['date_from'] === null
        && $unsafeQueue['filtersAdjusted'] === true,
        'Unsupported status, sort, product, and date values fall back through allow lists instead of reaching SQL.'
    );

    // === Authorization ===
    $activeAdministrator = $administratorAuthorization->activeAdministrator($administratorId);
    $check(is_array($activeAdministrator), 'An active administrator passes the server-side authorization check.');
    $databaseConnection->prepare("UPDATE administrators_accounts SET account_status = 'SUSPENDED' WHERE id = :id")
        ->execute(['id' => $administratorId]);
    $check(
        $administratorAuthorization->activeAdministrator($administratorId) === null,
        'A suspended administrator is rejected before production routes can proceed.'
    );
} finally {
    if ($orderIds !== []) {
        $ids = array_values(array_unique($orderIds));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        // order_items rows go through ON DELETE CASCADE; migration 015 blocks deleting
        // single lines of a price-locked order, and FK cascades do not fire that trigger.
        $databaseConnection->prepare("DELETE FROM orders WHERE id IN ({$placeholders})")->execute($ids);
    }
    if ($productIds !== []) {
        $ids = array_values(array_unique($productIds));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $databaseConnection->prepare("DELETE FROM set_components WHERE set_product_id IN ({$placeholders}) OR component_product_id IN ({$placeholders})")->execute(array_merge($ids, $ids));
        $databaseConnection->prepare("DELETE FROM product_materials WHERE product_id IN ({$placeholders})")->execute($ids);
        $databaseConnection->prepare("DELETE FROM product_translations WHERE product_id IN ({$placeholders})")->execute($ids);
        $databaseConnection->prepare("DELETE FROM products WHERE id IN ({$placeholders})")->execute($ids);
    }
    if ($materialIds !== []) {
        $ids = array_values(array_unique($materialIds));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $databaseConnection->prepare("DELETE FROM product_materials WHERE material_id IN ({$placeholders})")->execute($ids);
        $databaseConnection->prepare("DELETE FROM material_stock_movements WHERE material_id IN ({$placeholders})")->execute($ids);
        $databaseConnection->prepare("DELETE FROM materials WHERE id IN ({$placeholders})")->execute($ids);
    }
    if ($administratorId !== null) {
        $databaseConnection->prepare('DELETE FROM administrators_accounts WHERE id = :id')->execute(['id' => $administratorId]);
    }
}

foreach ($results as $result) {
    echo $result . PHP_EOL;
}
echo 'Production workflow verification completed without leaving test rows.' . PHP_EOL;
