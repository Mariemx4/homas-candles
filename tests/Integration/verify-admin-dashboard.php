<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Integration verification for Verify Admin Dashboard.
 *
 * DATA FLOW: This executable test crosses application and persistence boundaries to confirm the feature layers work together.
 */

// SETUP STEP: Load the application code or delegated test required before this command can run.
require_once __DIR__ . '/../../app/Bootstrap/autoload.php';
// SETUP STEP: Load the application code or delegated test required before this command can run.
require_once __DIR__ . '/../../app/config/database.php';

use App\Repositories\AdminDashboardRepository;
use App\Security\CsrfTokenManager;
use App\Services\AdminDashboardService;

// DATABASE SETUP: Open the real test/development connection used by this integration operation.
$connection = database();
$repository = new AdminDashboardRepository($connection);
$service = new AdminDashboardService($repository);
$results = [];
$check = static function (bool $condition, string $message) use (&$results): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $results[] = '[PASS] ' . $message;
};

$today = new DateTimeImmutable('2026-08-28');
$suffix = substr((string) hrtime(true), -8);
$orderSequence = 0;

// ISOLATION STEP: Start a transaction so test changes can be rolled back instead of polluting development data.
$connection->beginTransaction();

try {
    $insertOrder = $connection->prepare(
        'INSERT INTO orders
            (order_number, language_code, order_status, delivery_method, required_date,
             customer_name_snapshot, customer_phone_snapshot, subtotal, delivery_fee,
             total_amount, required_deposit_amount)
         VALUES
            (:order_number, :language_code, :order_status, :delivery_method, :required_date,
             :customer_name, :customer_phone, :subtotal, :delivery_fee,
             :total_amount, :required_deposit_amount)'
    );
    $insertTestOrder = static function (string $status, string $date, string $customerName = 'Dashboard Customer') use (
        $insertOrder,
        $connection,
        $suffix,
        &$orderSequence
    ): int {
        $orderSequence++;
        $insertOrder->execute([
            'order_number' => "DASH-{$suffix}-{$orderSequence}",
            'language_code' => 'en',
            'order_status' => $status,
            'delivery_method' => 'PICKUP',
            'required_date' => $date,
            'customer_name' => $customerName,
            'customer_phone' => '90000001',
            'subtotal' => '50.000',
            'delivery_fee' => '0.000',
            'total_amount' => '50.000',
            'required_deposit_amount' => '0.000',
        ]);

        return (int) $connection->lastInsertId();
    };

    $overdueId = $insertTestOrder('REQUESTED', '2026-08-27');
    $todayId = $insertTestOrder('REQUESTED', '2026-08-28', '<script>Customer</script>');
    $boundaryId = $insertTestOrder('CONFIRMED', '2026-09-04');
    $insertTestOrder('CONFIRMED', '2026-09-05');
    $insertTestOrder('DELIVERED', '2026-08-28');
    $insertTestOrder('PICKED_UP', '2026-08-28');

    $payment = $connection->prepare(
        "INSERT INTO payments
            (order_id, payment_method, payment_type, payment_status, amount, currency)
         VALUES (:order_id, 'CASH_ON_DELIVERY', 'FULL_PAYMENT', 'PENDING_VERIFICATION', 50.000, 'TND')"
    );
    $payment->execute(['order_id' => $todayId]);

    $request = $connection->prepare(
        'INSERT INTO custom_product_requests
            (request_number, category_id, customer_name_snapshot, customer_phone_snapshot,
             preferred_contact_method, required_date, request_status)
         VALUES
            (:request_number, 1, :customer_name, :customer_phone,
             :contact_method, :required_date, :request_status)'
    );
    foreach ([['SUBMITTED', 'OPEN'], ['DECLINED', 'CLOSED']] as [$status, $label]) {
        $request->execute([
            'request_number' => "DASH-REQ-{$suffix}-{$label}",
            'customer_name' => 'Request Customer',
            'customer_phone' => '90000002',
            'contact_method' => 'PHONE',
            'required_date' => '2026-09-01',
            'request_status' => $status,
        ]);
    }

    $material = $connection->prepare(
        'INSERT INTO materials
            (material_type_id, sku, name, unit, stock_quantity, unit_cost, tracks_stock, is_active)
         VALUES (1, :sku, :name, :unit, :stock_quantity, 1.000, 1, 1)'
    );
    $material->execute([
        'sku' => "DASH-LOW-{$suffix}",
        'name' => 'Dashboard low stock',
        'unit' => 'piece',
        'stock_quantity' => '0.500',
    ]);

    $overview = $service->overview($today);
    $check($overview['metrics']['requestedOrders'] === 2, 'Requested-order count uses the exact REQUESTED status.');
    $check($overview['metrics']['ordersDueSoon'] === 2, 'Due-soon count includes today and day seven, but excludes overdue and terminal orders.');
    $check($overview['metrics']['pendingPayments'] === 1, 'Only pending-verification payments are counted.');
    $check($overview['metrics']['openRequests'] === 1, 'Converted, declined, and cancelled request states are excluded.');
    $check(
        (string) $overview['metrics']['lowestTrackedStock']['stock_quantity'] === '0.500',
        'Inventory summary reports the lowest active tracked quantity without inventing a threshold.'
    );
    $check(
        ($overview['urgentActions'][0]['kind'] ?? null) === 'Overdue'
        && ($overview['urgentActions'][0]['title'] ?? null) === "DASH-{$suffix}-1",
        'Urgent work is ordered with overdue orders first and labels urgency in text.'
    );
    $upcomingIds = array_map(static fn (array $row): int => (int) $row['id'], $overview['upcomingOrders']);
    $check(
        in_array($todayId, $upcomingIds, true)
        && in_array($boundaryId, $upcomingIds, true)
        && !in_array($overdueId, $upcomingIds, true),
        'Upcoming work is limited to non-terminal orders from today onward.'
    );
    $check(
        !array_key_exists('customer_email_snapshot', $overview['upcomingOrders'][0])
        && !array_key_exists('customer_phone_snapshot', $overview['upcomingOrders'][0])
        && !array_key_exists('payment_proof_path', $overview['pendingPayments'][0]),
        'Dashboard repository results omit private contact details and payment evidence.'
    );

    $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $tableCaption = 'Escaping check';
    $tableRows = [['customer_name_snapshot' => '<script>Customer</script>']];
    $tableColumns = [['label' => 'Customer', 'key' => 'customer_name_snapshot']];
    $emptyTitle = 'Nothing here';
    $emptyMessage = 'No rows.';
    ob_start();
    // SETUP STEP: Load the application code or delegated test required before this command can run.
    require __DIR__ . '/../../app/Views/Components/Admin/data-table.php';
    $renderedTable = (string) ob_get_clean();
    $check(
        str_contains($renderedTable, '&lt;script&gt;Customer&lt;/script&gt;')
        && !str_contains($renderedTable, '<script>Customer</script>'),
        'Reusable table output escapes untrusted database values.'
    );

    $tableRows = [];
    ob_start();
    // SETUP STEP: Load the application code or delegated test required before this command can run.
    require __DIR__ . '/../../app/Views/Components/Admin/data-table.php';
    $emptyTable = (string) ob_get_clean();
    $check(str_contains($emptyTable, 'Nothing here'), 'Empty datasets render a useful state without fake rows.');

    if (!defined('BASE_URL')) {
        define('BASE_URL', '/homas-candles/public/');
    }
    $administrator = [
        'first_name' => '<Admin>',
        'last_name' => 'Verifier',
    ];
    $dashboard = $overview + ['loadError' => null];
    $pageTitle = 'Dashboard';
    $contentView = __DIR__ . '/../../app/Views/Pages/admin-dashboard.php';
    $csrf = new CsrfTokenManager();
    ob_start();
    // SETUP STEP: Load the application code or delegated test required before this command can run.
    require __DIR__ . '/../../app/Views/Layouts/admin.php';
    $renderedDashboard = (string) ob_get_clean();
    $check(
        str_contains($renderedDashboard, '&lt;Admin&gt;')
        && str_contains($renderedDashboard, '&lt;script&gt;Customer&lt;/script&gt;')
        && !str_contains($renderedDashboard, '<script>Customer</script>'),
        'The complete dashboard layout safely escapes administrator and customer display names.'
    );
    $check(
        str_contains($renderedDashboard, 'aria-expanded="false"')
        && str_contains($renderedDashboard, 'admin/orders/index.php')
        && str_contains($renderedDashboard, 'admin/customers/index.php'),
        'The mobile menu exposes its state and links to the implemented order and customer pages.'
    );
} finally {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
}

foreach ($results as $result) {
    echo $result . PHP_EOL;
}
echo 'Administrator dashboard verification completed without leaving test rows.' . PHP_EOL;
