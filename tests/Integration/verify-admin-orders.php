<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Integration verification for Verify Admin Orders.
 *
 * DATA FLOW: This executable test crosses application and persistence boundaries to confirm the feature layers work together.
 */

// SETUP STEP: Load the application code or delegated test required before this command can run.
require_once __DIR__ . '/../../app/Bootstrap/autoload.php';
// SETUP STEP: Load the application code or delegated test required before this command can run.
require_once __DIR__ . '/../../app/config/database.php';

use App\Controllers\OrderController;
use App\Repositories\OrderRepository;
use App\Services\OrderService;

// DATABASE SETUP: Open the real test/development connection used by this integration operation.
$connection = database();
$repository = new OrderRepository($connection);
$service = new OrderService($repository);
$controller = new OrderController($service);
$today = new DateTimeImmutable('2026-08-28');
$results = [];
$check = static function (bool $condition, string $message) use (&$results): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $results[] = '[PASS] ' . $message;
};

$suffix = substr((string) hrtime(true), -8);
$literalMarker = "Order%_={$suffix}";
// ISOLATION STEP: Start a transaction so test changes can be rolled back instead of polluting development data.
$connection->beginTransaction();

try {
    $insertOrder = $connection->prepare(
        'INSERT INTO orders
            (order_number, language_code, order_source, order_status, delivery_method,
             required_date, customer_name_snapshot, customer_email_snapshot,
             customer_phone_snapshot, subtotal, delivery_fee, total_amount,
             required_deposit_amount, customer_notes, administrator_notes)
         VALUES
            (:order_number, :language_code, :order_source, :order_status, :delivery_method,
             :required_date, :customer_name, :customer_email,
             :customer_phone, :subtotal, :delivery_fee, :total_amount,
             :required_deposit, :customer_notes, :administrator_notes)'
    );

    $orderIds = [];
    for ($index = 1; $index <= 21; $index++) {
        $total = number_format(40 + $index, 3, '.', '');
        $insertOrder->execute([
            'order_number' => "ORD-M02-{$suffix}-{$index}",
            'language_code' => 'en',
            'order_source' => 'CART',
            'order_status' => 'REQUESTED',
            'delivery_method' => 'PICKUP',
            'required_date' => '2026-09-01',
            'customer_name' => $literalMarker,
            'customer_email' => 'private-order@example.test',
            'customer_phone' => '90000003',
            'subtotal' => $total,
            'delivery_fee' => '0.000',
            'total_amount' => $total,
            'required_deposit' => '0.000',
            'customer_notes' => '<script>private customer note</script>',
            'administrator_notes' => 'Private preparation note',
        ]);
        $orderIds[] = (int) $connection->lastInsertId();
    }
    $detailOrderId = $orderIds[0];

    $decoyOrder = $connection->prepare(
        "INSERT INTO orders
            (order_number, language_code, order_source, order_status, delivery_method,
             required_date, customer_name_snapshot, customer_phone_snapshot,
             subtotal, delivery_fee, total_amount, required_deposit_amount)
         VALUES
            (:order_number, 'en', 'CART', 'REQUESTED', 'PICKUP', '2026-09-01',
             :customer_name, '90000004', 45.000, 0.000, 45.000, 0.000)"
    );
    $decoyOrder->execute([
        'order_number' => "ORD-M02-DECOY-{$suffix}",
        'customer_name' => "OrderXX={$suffix}",
    ]);

    $item = $connection->prepare(
        'INSERT INTO order_items
            (order_id, product_id, product_name_snapshot, selected_color_id,
             color_name_snapshot, selected_scent_id, scent_name_snapshot,
             quantity, unit_price_snapshot, line_total_snapshot, customization_notes)
         VALUES
            (:order_id, NULL, :product_name, NULL, NULL, NULL, NULL,
             2, 12.000, 24.000, :customization_notes)'
    );
    $item->execute([
        'order_id' => $detailOrderId,
        'product_name' => '<Legacy Candle>',
        'customization_notes' => 'Literal snapshot survives missing catalogue references.',
    ]);

    $payment = $connection->prepare(
        'INSERT INTO payments
            (order_id, payment_method, payment_type, payment_status,
             amount, currency, verified_at)
         VALUES
            (:order_id, :method, :type, :status, :amount, :currency, :verified_at)'
    );
    $payment->execute([
        'order_id' => $detailOrderId,
        'method' => 'CASH_ON_DELIVERY',
        'type' => 'DEPOSIT',
        'status' => 'VERIFIED',
        'amount' => '10.125',
        'currency' => 'TND',
        'verified_at' => '2026-08-28 10:00:00',
    ]);
    $payment->execute([
        'order_id' => $detailOrderId,
        'method' => 'CASH_ON_DELIVERY',
        'type' => 'BALANCE',
        'status' => 'PENDING_VERIFICATION',
        'amount' => '5.000',
        'currency' => 'TND',
        'verified_at' => null,
    ]);

    $history = $connection->prepare(
        "INSERT INTO order_status_history
            (order_id, previous_status, new_status, change_source, change_note, changed_at)
         VALUES (:order_id, NULL, 'REQUESTED', 'SYSTEM', :change_note, '2026-08-28 09:00:00')"
    );
    $history->execute(['order_id' => $detailOrderId, 'change_note' => 'Order submitted.']);

    $list = $service->list([
        'q' => "%_={$suffix}",
        'status' => 'REQUESTED',
        'source' => 'CART',
        'method' => 'PICKUP',
        'date_from' => '2026-09-01',
        'date_to' => '2026-09-01',
        'sort' => 'total',
        'direction' => 'desc',
        'page' => '2',
    ], $today);

    $check($list['total'] === 21, 'Search treats SQL wildcard characters as literal customer text.');
    $check(
        $list['page'] === 2 && $list['totalPages'] === 2 && count($list['orders']) === 1,
        'Filters, sorting, and pagination work together across a page boundary.'
    );
    $check(
        $list['orders'][0]['id'] === $detailOrderId
        && $list['orders'][0]['total_amount'] === '41.000',
        'Allow-listed total sorting returns the expected second-page record.'
    );
    $check(
        !array_key_exists('customer_email_snapshot', $list['orders'][0])
        && !array_key_exists('customer_phone_snapshot', $list['orders'][0]),
        'Order-list rows expose no customer email, phone, address, or notes.'
    );
    $check(
        str_contains($list['returnQuery'], 'page=2')
        && str_contains($list['returnQuery'], 'sort=total'),
        'Normalized list state is preserved for detail-page return links.'
    );

    $invalid = $service->normalizeListQuery([
        'status' => "REQUESTED' OR 1=1 --",
        'sort' => 'total_amount DESC; DROP TABLE orders',
        'direction' => 'sideways',
        'date_from' => '2026-99-99',
        'page' => '-5',
    ]);
    $check(
        $invalid['filters']['status'] === null
        && $invalid['sort'] === 'required_date'
        && $invalid['direction'] === 'asc'
        && $invalid['filters']['date_from'] === null
        && $invalid['page'] === 1
        && $invalid['adjusted'] === true,
        'Unsupported filters, sort structure, dates, and pages fall back through allow lists.'
    );

    $pastBoundary = $service->list(['q' => "%_={$suffix}", 'page' => '999'], $today);
    $check($pastBoundary['page'] === 2, 'Pages beyond the result boundary clamp to the final page.');

    $detail = $service->detail($detailOrderId, $today);
    $check(
        is_array($detail)
        && $detail['items'][0]['product_id'] === null
        && $detail['items'][0]['color_name_snapshot'] === null
        && $detail['items'][0]['scent_name_snapshot'] === null
        && $detail['items'][0]['product_name_snapshot'] === '<Legacy Candle>',
        'Order detail uses stored item snapshots and tolerates missing product, color, and scent references.'
    );
    $check(
        $detail['order']['amount_paid'] === '10.125'
        && $detail['order']['remaining_balance'] === '30.875',
        'Only verified payments reduce the balance, at exact three-decimal precision.'
    );
    $check(
        $detail['order']['street_address_snapshot'] === null
        && count($detail['payments']) === 2
        && count($detail['history']) === 1,
        'Optional pickup address, payment summary, and status history load safely without N+1 item queries.'
    );

    $badIdentifier = $controller->show(['id' => '1 OR 1=1'], $today);
    $missingOrder = $controller->show(['id' => '9223372036854775807'], $today);
    $check(
        $badIdentifier['httpStatus'] === 400 && $missingOrder['httpStatus'] === 404,
        'Invalid and nonexistent order identifiers receive safe, distinct HTTP outcomes.'
    );

    if (!defined('BASE_URL')) {
        define('BASE_URL', '/homas-candles/public/');
    }
    $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $orderDetail = $detail + ['loadError' => null, 'httpStatus' => 200, 'backQuery' => $list['returnQuery']];
    $backUrl = BASE_URL . 'admin/orders/index.php?' . $list['returnQuery'];
    ob_start();
    // SETUP STEP: Load the application code or delegated test required before this command can run.
    require __DIR__ . '/../../app/Views/Pages/admin-order/admin-order-detail.php';
    $detailHtml = (string) ob_get_clean();
    $check(
        str_contains($detailHtml, '&lt;Legacy Candle&gt;')
        && str_contains($detailHtml, '&lt;script&gt;private customer note&lt;/script&gt;')
        && !str_contains($detailHtml, '<script>private customer note</script>'),
        'Protected detail output escapes stored snapshot and note content.'
    );

    ob_start();
    // SETUP STEP: Load the application code or delegated test required before this command can run.
    require __DIR__ . '/../../app/Views/Pages/admin-order/admin-order-print.php';
    $printHtml = (string) ob_get_clean();
    $check(
        !str_contains($printHtml, '90000003')
        && !str_contains($printHtml, 'private-order@example.test')
        && !str_contains($printHtml, 'Private preparation note')
        && str_contains($printHtml, '&lt;Legacy Candle&gt;'),
        'Print summary retains preparation snapshots while omitting contact details and private notes.'
    );
} finally {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
}

foreach ($results as $result) {
    echo $result . PHP_EOL;
}
echo 'Administrator orders verification completed without leaving test rows.' . PHP_EOL;
