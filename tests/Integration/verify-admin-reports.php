<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Integration verification for Verify Admin Reports.
 *
 * DATA FLOW: This executable test crosses application and persistence boundaries to confirm the feature layers work together.
 */

require_once __DIR__ . '/../../app/config/paths.php';
ini_set('session.save_path', TEST_SESSION_PATH);
// SETUP STEP: Load the application code or delegated test required before this command can run.
require_once __DIR__ . '/../../app/Bootstrap/admin/admin-reports.php';

$results = [];
$check = static function (bool $condition, string $message) use (&$results): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $results[] = '[PASS] ' . $message;
};

$suffix = substr((string) hrtime(true), -8);
$administratorIds = [];
$orderIds = [];
$requestIds = [];
$paymentIds = [];
$movementIds = [];
$categoryId = 0;
$materialTypeId = 0;
$materialId = 0;

try {
    $passwordHash = password_hash('AdminReportVerifier123!', PASSWORD_DEFAULT);
    $insertAdmin = $databaseConnection->prepare(
        'INSERT INTO administrators_accounts
            (first_name, last_name, email, phone_number, password_hash, account_status)
         VALUES (:first_name, :last_name, :email, :phone, :password_hash, :status)'
    );
    foreach ([['ACTIVE', 'Active', '52'], ['SUSPENDED', 'Suspended', '53']] as [$status, $name, $prefix]) {
        $insertAdmin->execute([
            'first_name' => $name,
            'last_name' => 'Report Verifier',
            'email' => strtolower($name) . "-report-{$suffix}@example.test",
            'phone' => $prefix . substr(str_pad($suffix, 8, '0', STR_PAD_LEFT), -6),
            'password_hash' => $passwordHash,
            'status' => $status,
        ]);
        $administratorIds[$status] = (int) $databaseConnection->lastInsertId();
    }
    $activeId = $administratorIds['ACTIVE'];

    $databaseConnection->prepare('INSERT INTO categories (slug, is_active) VALUES (:slug, 1)')
        ->execute(['slug' => "report-category-{$suffix}"]);
    $categoryId = (int) $databaseConnection->lastInsertId();
    $databaseConnection->prepare(
        'INSERT INTO material_types (slug, is_consumable, is_recipe_input, is_active)
         VALUES (:slug, 1, 1, 1)'
    )->execute(['slug' => "report-material-type-{$suffix}"]);
    $materialTypeId = (int) $databaseConnection->lastInsertId();
    $databaseConnection->prepare(
        'INSERT INTO materials
            (material_type_id, sku, name, unit, stock_quantity, unit_cost, tracks_stock, is_active)
         VALUES (:type_id, :sku, :name, \'g\', 7.000, 4.000, 1, 1)'
    )->execute(['type_id' => $materialTypeId, 'sku' => "RPT-{$suffix}", 'name' => '=Formula Wax']);
    $materialId = (int) $databaseConnection->lastInsertId();

    $insertOrder = $databaseConnection->prepare(
        'INSERT INTO orders
            (order_number, created_by_administrator_id, language_code, order_source,
             order_status, delivery_method, required_date, customer_name_snapshot,
             customer_email_snapshot, customer_phone_snapshot, subtotal, delivery_fee,
             total_amount, required_deposit_amount, administrator_notes, created_at)
         VALUES (:number, :administrator_id, \'en\', \'MANUAL\', :status, \'PICKUP\',
                 \'2026-09-10\', :customer_name, :customer_email, \'77000000\',
                 :subtotal, 0.000, :total, 0.000, :private_notes, :created_at)'
    );
    for ($index = 1; $index <= 27; $index++) {
        $status = $index === 1 ? 'PICKED_UP' : ($index === 2 ? 'DELIVERED' : 'REQUESTED');
        $total = $index === 1 ? '20.000' : ($index === 2 ? '30.000' : '10.000');
        $insertOrder->execute([
            'number' => ($index === 1 ? '=RPT-' : 'RPT-') . "{$suffix}-{$index}",
            'administrator_id' => $activeId,
            'status' => $status,
            'customer_name' => '=Private Customer',
            'customer_email' => 'private@example.test',
            'subtotal' => $total,
            'total' => $total,
            'private_notes' => 'PRIVATE-NOTE-MUST-NOT-EXPORT',
            'created_at' => '2026-08-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT) . ' 12:00:00',
        ]);
        $orderIds[] = (int) $databaseConnection->lastInsertId();
    }
    foreach ([['RPT-BEFORE', '2026-07-31 23:59:59'], ['RPT-AFTER', '2026-09-01 00:00:00']] as [$label, $createdAt]) {
        $insertOrder->execute([
            'number' => "{$label}-{$suffix}", 'administrator_id' => $activeId,
            'status' => 'PICKED_UP', 'customer_name' => 'Boundary', 'customer_email' => null,
            'subtotal' => '99.000', 'total' => '99.000', 'private_notes' => null, 'created_at' => $createdAt,
        ]);
        $orderIds[] = (int) $databaseConnection->lastInsertId();
    }

    $insertItem = $databaseConnection->prepare(
        'INSERT INTO order_items
            (order_id, product_id, product_name_snapshot, color_name_snapshot,
             scent_name_snapshot, quantity, unit_price_snapshot, unit_cost_snapshot,
             line_total_snapshot)
         VALUES (:order_id, NULL, :product, :color, :scent, :quantity, :unit_price, :unit_cost, :line_total)'
    );
    $insertItem->execute(['order_id' => $orderIds[0], 'product' => '=Danger Candle', 'color' => 'Pink', 'scent' => 'Vanilla', 'quantity' => 2, 'unit_price' => '10.000', 'unit_cost' => '3.000', 'line_total' => '20.000']);
    $insertItem->execute(['order_id' => $orderIds[1], 'product' => 'Costless Candle', 'color' => null, 'scent' => null, 'quantity' => 1, 'unit_price' => '30.000', 'unit_cost' => null, 'line_total' => '30.000']);

    $insertPayment = $databaseConnection->prepare(
        'INSERT INTO payments
            (order_id, payment_method, payment_type, payment_status, amount, currency,
             verified_at, verified_by_administrator_id, rejection_reason)
         VALUES (:order_id, \'CASH_ON_DELIVERY\', \'BALANCE\', :status, :amount, \'TND\',
                 :verified_at, :administrator_id, :rejection_reason)'
    );
    foreach ([
        [$orderIds[0], 'VERIFIED', '10.000', '2026-08-01 00:00:00', null],
        [$orderIds[1], 'VERIFIED', '5.000', '2026-08-31 23:59:59', null],
        [$orderIds[0], 'VERIFIED', '90.000', '2026-09-01 00:00:00', null],
        [$orderIds[0], 'REJECTED', '7.000', null, 'Rejected fixture'],
    ] as [$orderId, $status, $amount, $verifiedAt, $reason]) {
        $insertPayment->execute(['order_id' => $orderId, 'status' => $status, 'amount' => $amount, 'verified_at' => $verifiedAt, 'administrator_id' => $status === 'VERIFIED' ? $activeId : null, 'rejection_reason' => $reason]);
        $paymentIds[] = (int) $databaseConnection->lastInsertId();
    }

    $insertRequest = $databaseConnection->prepare(
        'INSERT INTO custom_product_requests
            (request_number, category_id, customer_name_snapshot, customer_email_snapshot,
             customer_phone_snapshot, preferred_contact_method, customer_message,
             request_status, converted_order_id, converted_at, created_at)
         VALUES (:number, :category_id, :customer_name, :customer_email, \'78000000\',
                 \'EMAIL\', :private_message, :status, :order_id, :converted_at, :created_at)'
    );
    for ($index = 1; $index <= 3; $index++) {
        $converted = $index === 1;
        $insertRequest->execute([
            'number' => "REQ-RPT-{$suffix}-{$index}", 'category_id' => $categoryId,
            'customer_name' => '@Private Requester', 'customer_email' => 'request@example.test',
            'private_message' => 'PRIVATE-REQUEST-MESSAGE', 'status' => $converted ? 'CONVERTED' : 'SUBMITTED',
            'order_id' => $converted ? $orderIds[0] : null, 'converted_at' => $converted ? '2026-08-15 12:00:00' : null,
            'created_at' => "2026-08-0{$index} 10:00:00",
        ]);
        $requestIds[] = (int) $databaseConnection->lastInsertId();
    }

    $insertMovement = $databaseConnection->prepare(
        'INSERT INTO material_stock_movements
            (material_id, order_id, movement_type, quantity_change, unit_cost_snapshot,
             stock_before, stock_after, performed_by_administrator_id, movement_note, created_at)
         VALUES (:material_id, :order_id, :type, :change, 4.000, :before, :after,
                 :administrator_id, :note, :created_at)'
    );
    foreach ([
        [$orderIds[0], 'ORDER_DEDUCTION', '-2.000', '10.000', '8.000', null, '2026-08-10 12:00:00'],
        [null, 'DAMAGED_OR_WASTED', '-1.000', '8.000', '7.000', 'Waste fixture', '2026-08-11 12:00:00'],
    ] as [$orderId, $type, $change, $before, $after, $note, $createdAt]) {
        $insertMovement->execute(['material_id' => $materialId, 'order_id' => $orderId, 'type' => $type, 'change' => $change, 'before' => $before, 'after' => $after, 'administrator_id' => $activeId, 'note' => $note, 'created_at' => $createdAt]);
        $movementIds[] = (int) $databaseConnection->lastInsertId();
    }

    $query = ['start' => '2026-08-01', 'end' => '2026-08-31', 'section' => 'orders', 'page' => '2'];
    $report = $adminReportService->report($query, new DateTimeImmutable('2026-08-31'));
    $check((int) $report['summary']['orders']['order_count'] === 27 && (int) $report['summary']['orders']['completed_count'] === 2, 'Inclusive date boundaries count only in-range orders.');
    $check($report['summary']['orders']['completed_value'] === '50.000' && $report['summary']['orders']['known_cost'] === '6.000' && $report['summary']['estimated_profit'] === '44.000', 'Completed value, known snapshot cost, and estimated margin use documented definitions.');
    $check((int) $report['summary']['orders']['missing_cost_items'] === 1, 'Missing item costs remain visible instead of silently becoming authoritative estimates.');
    $check($report['summary']['payments']['verified_amount'] === '15.000' && (int) $report['summary']['payments']['verified_count'] === 2, 'Verified payment totals exclude rejected and out-of-range records.');
    $check($report['summary']['requests']['conversion_rate'] === '33.3', 'Request conversion uses requests created in range and current converted state.');
    $check($report['summary']['materials']['used_value'] === '8.000' && $report['summary']['materials']['waste_value'] === '4.000', 'Material use and waste use immutable ledger quantity and cost snapshots.');
    $check($report['pagination']['page'] === 2 && $report['pagination']['total'] === 27 && count($report['details']) === 2, 'Supporting report rows are paginated in bounded 25-row pages.');
    $check($report['top']['products'][0]['item_name'] === '=Danger Candle' && (int) $report['top']['products'][0]['quantity'] === 2, 'Top products retain historical snapshots even without a live product link.');

    $badDates = $adminReportService->report(['start' => '2026-09-01', 'end' => '2026-08-01', 'section' => 'invalid'], new DateTimeImmutable('2026-08-31'));
    $check($badDates['filters']['errors'] !== [] && $badDates['filters']['section'] === 'orders', 'Invalid ranges and arbitrary report sections fail closed to validated defaults.');
    $badExport = $adminReportController->export(['start' => '2026-13-01', 'end' => '2026-08-31', 'section' => 'orders'], new DateTimeImmutable('2026-08-31'));
    $check($badExport['httpStatus'] === 422, 'CSV export rejects invalid filters instead of silently broadening scope.');

    $export = $adminReportService->export(['start' => '2026-08-01', 'end' => '2026-08-31', 'section' => 'orders'], new DateTimeImmutable('2026-08-31'));
    $check(count($export['rows']) === 27 && !array_key_exists('customer_name_snapshot', $export['rows'][0]) && !array_key_exists('administrator_notes', $export['rows'][0]), 'Export projection excludes customer identity and private order notes.');
    $stream = fopen('php://temp', 'w+b');
    $adminReportCsvService->write($stream, $export['columns'], $export['rows']);
    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);
    $check(is_string($csv) && str_contains($csv, "'=RPT-") && !str_contains($csv, 'PRIVATE-NOTE'), 'CSV formula injection is neutralized and sensitive notes are absent.');

    $databaseConnection->prepare('UPDATE orders SET deleted_at = :deleted_at, deleted_by_administrator_id = :administrator_id WHERE id = :id')
        ->execute(['deleted_at' => '2026-09-02 10:00:00', 'administrator_id' => $activeId, 'id' => $orderIds[0]]);
    $retainedReport = $adminReportService->report($query, new DateTimeImmutable('2026-08-31'));
    $trashedRows = array_values(array_filter($retainedReport['details'],
        static fn (array $row): bool => (int) $row['id'] === $orderIds[0]));
    $check((int) $retainedReport['summary']['orders']['order_count'] === 27
        && (int) $retainedReport['summary']['orders']['completed_count'] === 2
        && $retainedReport['summary']['orders']['completed_value'] === '50.000'
        && $retainedReport['summary']['orders']['known_cost'] === '6.000'
        && $retainedReport['summary']['estimated_profit'] === '44.000'
        && $retainedReport['summary']['payments']['verified_amount'] === '15.000'
        && $retainedReport['top']['products'][0]['item_name'] === '=Danger Candle',
        'Trashing a completed order preserves sales, costs, margin, rankings, and verified payment totals.');
    $check(count($trashedRows) === 1 && $trashedRows[0]['deleted_at'] === '2026-09-02 10:00:00',
        'Order report detail identifies the retained record as being in Trash.');
    $retainedExport = $adminReportService->export(['start' => '2026-08-01', 'end' => '2026-08-31', 'section' => 'orders'], new DateTimeImmutable('2026-08-31'));
    $exportTrashedRows = array_values(array_filter($retainedExport['rows'],
        static fn (array $row): bool => (int) $row['id'] === $orderIds[0]));
    $check(count($retainedExport['rows']) === 27 && count($exportTrashedRows) === 1
        && $exportTrashedRows[0]['deleted_at'] === '2026-09-02 10:00:00'
        && isset($retainedExport['columns']['deleted_at']),
        'Order CSV export retains completed orders in Trash and labels their visibility.');
    $paymentExport = $adminReportService->export(['start' => '2026-08-01', 'end' => '2026-08-31', 'section' => 'payments'], new DateTimeImmutable('2026-08-31'));
    $trashedPayments = array_values(array_filter($paymentExport['rows'],
        static fn (array $row): bool => (int) $row['order_id'] === $orderIds[0]));
    $check(count($paymentExport['rows']) === 2 && count($trashedPayments) === 1
        && $trashedPayments[0]['order_trashed_at'] === '2026-09-02 10:00:00'
        && isset($paymentExport['columns']['order_trashed_at']),
        'Verified-payment CSV export identifies payments linked to orders in Trash.');
    $check(is_array($administratorAuthorization->activeAdministrator($activeId)) && $administratorAuthorization->activeAdministrator($administratorIds['SUSPENDED']) === null, 'Reports authorize active administrators and reject suspended administrators.');
} finally {
    if ($movementIds !== []) {
        $placeholders = implode(',', array_fill(0, count($movementIds), '?'));
        $databaseConnection->prepare("DELETE FROM material_stock_movements WHERE id IN ({$placeholders})")->execute($movementIds);
    }
    if ($requestIds !== []) {
        $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
        $databaseConnection->prepare("DELETE FROM custom_product_requests WHERE id IN ({$placeholders})")->execute($requestIds);
    }
    if ($paymentIds !== []) {
        $placeholders = implode(',', array_fill(0, count($paymentIds), '?'));
        $databaseConnection->prepare("DELETE FROM payments WHERE id IN ({$placeholders})")->execute($paymentIds);
    }
    if ($orderIds !== []) {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        // order_items rows go through ON DELETE CASCADE; migration 015 blocks deleting
        // single lines of a price-locked order, and FK cascades do not fire that trigger.
        $databaseConnection->prepare("DELETE FROM orders WHERE id IN ({$placeholders})")->execute($orderIds);
    }
    if ($materialId > 0) {
        $databaseConnection->prepare('DELETE FROM materials WHERE id = :id')->execute(['id' => $materialId]);
    }
    if ($materialTypeId > 0) {
        $databaseConnection->prepare('DELETE FROM material_types WHERE id = :id')->execute(['id' => $materialTypeId]);
    }
    if ($categoryId > 0) {
        $databaseConnection->prepare('DELETE FROM categories WHERE id = :id')->execute(['id' => $categoryId]);
    }
    foreach ($administratorIds as $administratorId) {
        $databaseConnection->prepare('DELETE FROM administrators_accounts WHERE id = :id')->execute(['id' => $administratorId]);
    }
}

foreach ($results as $result) {
    echo $result . PHP_EOL;
}
echo 'Admin-report verification completed without leaving test rows.' . PHP_EOL;
