<?php

declare(strict_types=1);



require_once __DIR__ . '/../../app/Bootstrap/autoload.php';
require_once __DIR__ . '/../Support/throwaway-auth-schema.php';
require_once __DIR__ . '/../Support/TestCheck.php';
ini_set('session.save_path', TEST_SESSION_PATH);

use App\Repositories\AdminDashboardRepository;
use App\Security\CsrfTokenManager;
use App\Services\AdminDashboardService;

// DATABASE SETUP: Open the real test/development connection used by this integration operation.
[$connection, $schemaName] = create_throwaway_auth_schema(true);
$repository = new AdminDashboardRepository($connection);
$service = new AdminDashboardService($repository);
$results = [];
$check = TestCheck::collecting($results);

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
    $pendingPaymentId = (int) $connection->lastInsertId();

    $request = $connection->prepare(
        'INSERT INTO custom_product_requests
            (request_number, category_id, customer_name_snapshot, customer_phone_snapshot,
             preferred_contact_method, required_date, request_status, customer_message)
         VALUES
            (:request_number, 1, :customer_name, :customer_phone,
             :contact_method, :required_date, :request_status, :customer_message)'
    );
    foreach ([['SUBMITTED', 'OPEN'], ['DECLINED', 'CLOSED']] as [$status, $label]) {
        $request->execute([
            'request_number' => "DASH-REQ-{$suffix}-{$label}",
            'customer_name' => 'Request Customer',
            'customer_phone' => '90000002',
            'contact_method' => 'PHONE',
            'required_date' => '2026-09-01',
            'request_status' => $status,
            'customer_message' => '<script>30 floral candles, beige</script> ' . str_repeat('é', 120),
        ]);
    }

    $material = $connection->prepare(
        'INSERT INTO materials
            (material_type_id, sku, name, unit, stock_quantity, unit_cost, tracks_stock, is_active, minimum_stock_level)
         VALUES (1, :sku, :name, :unit, :stock_quantity, 1.000, :tracks_stock, :is_active, :minimum_stock_level)'
    );
    $material->execute([
        'sku' => "DASH-LOW-{$suffix}",
        'name' => 'Dashboard low stock',
        'unit' => 'piece',
        'stock_quantity' => '0.500',
        'minimum_stock_level' => '1.000',
        'tracks_stock' => 1,
        'is_active' => 1,
    ]);
    foreach (
        [
            ['WAX', 'kg', '3.500', '2.000', 1, 1],
            ['FRAGRANCE', 'ml', '80.000', '100.000', 1, 1],
            ['JARS', 'piece', '4.000', '10.000', 1, 1],
            ['EQUAL', 'g', '2.000', '2.000', 1, 1],
            ['ZERO', 'piece', '0.000', '0.000', 1, 1],
            ['LEGACY', 'ml', '0.000', null, 1, 1],
            ['INACTIVE', 'ml', '1.000', '10.000', 1, 0],
            ['UNTRACKED', 'piece', null, '10.000', 0, 1],
        ] as [$label, $unit, $quantity, $minimum, $tracking, $active]
    ) {
        $material->execute([
            'sku' => "DASH-{$label}-{$suffix}",
            'name' => $label,
            'unit' => $unit,
            'stock_quantity' => $quantity,
            'minimum_stock_level' => $minimum,
            'tracks_stock' => $tracking,
            'is_active' => $active
        ]);
    }

    $overview = $service->overview($today);
    $check($overview['metrics']['requestedOrders'] === 2, 'Requested-order count uses the exact REQUESTED status.');
    $check($overview['metrics']['ordersDueSoon'] === 2, 'Due-soon count includes today and day seven, but excludes overdue and terminal orders.');
    $check($overview['metrics']['pendingPayments'] === 1, 'Only pending-verification payments are counted.');
    $check($overview['metrics']['openRequests'] === 1, 'Converted, declined, and cancelled request states are excluded.');
    $check(
        $overview['metrics']['lowStockMaterials'] === 5 && $overview['unconfiguredMaterials'] === 1,
        'Low-stock count uses per-material thresholds, includes equality/explicit zero, and excludes missing, inactive and untracked thresholds.'
    );
    $lowNames = array_column($overview['lowStockMaterials'], 'name');
    $check(in_array('FRAGRANCE', $lowNames, true) && in_array('JARS', $lowNames, true) && !in_array('WAX', $lowNames, true), 'Different units are compared only with their own minimum.');
    $check($overview['urgentActions'][0]['deadline']['label'] === 'Overdue by 1 day', 'Urgent deadlines include calendar-day context.');
    $materials = new App\Repositories\MaterialRepository($connection);
    $check($materials->countForList(['stock_status' => 'low']) === 5 && $materials->countForList(['stock_status' => 'unconfigured']) === 1, 'Inventory attention filters match dashboard counts.');
    $orderService = new App\Services\OrderService(new App\Repositories\OrderRepository($connection));
    $due = $orderService->list(['status' => 'INCOMPLETE', 'date_from' => '2026-08-28', 'date_to' => '2026-09-04'], $today);
    $check($due['total'] === $overview['metrics']['ordersDueSoon'] && $due['queryState']['status'] === 'INCOMPLETE', 'Due-soon navigation keeps its incomplete status and date filters.');
    $requestRepository = new App\Repositories\CustomRequestRepository($connection);
    $check($requestRepository->countForList(['status' => 'OPEN']) === $overview['metrics']['openRequests'], 'Open-request navigation matches the dashboard metric.');
    $transactions = new App\Database\TransactionManager($connection);
    $requestService = new App\Services\CustomRequestService($requestRepository, $transactions);
    $openList = $requestService->list(['status' => 'OPEN'], $today);
    $check($openList['total'] === 1 && $openList['queryState']['status'] === 'OPEN' && !$openList['filtersAdjusted'], 'The existing request list loads and preserves its OPEN filter.');
    $paymentService = new App\Services\PaymentService(
        new App\Repositories\PaymentRepository($connection),
        $transactions,
        new App\Services\PaymentEvidenceStorage(PAYMENT_EVIDENCE_PATH)
    );
    $pendingList = $paymentService->list(['status' => 'PENDING_VERIFICATION']);
    $check($pendingList['total'] === 1 && $pendingList['filters']['status'] === 'PENDING_VERIFICATION', 'The existing pending payment list loads with its dashboard filter.');
    $check(str_starts_with($overview['recentRequests'][0]['summary'], '<script>30 floral candles')
        && str_ends_with($overview['recentRequests'][0]['summary'], '…')
        && preg_match('//u', $overview['recentRequests'][0]['summary']) === 1
        && !isset($overview['recentRequests'][0]['customer_message']), 'Request summaries truncate real message data without splitting UTF-8 characters.');
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
    require VIEW_PATH . '/Components/Admin/data-table.php';
    $renderedTable = (string) ob_get_clean();
    $check(
        str_contains($renderedTable, '&lt;script&gt;Customer&lt;/script&gt;')
            && !str_contains($renderedTable, '<script>Customer</script>'),
        'Reusable table output escapes untrusted database values.'
    );

    $tableRows = [];
    ob_start();
    require VIEW_PATH . '/Components/Admin/data-table.php';
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
    $contentView = VIEW_PATH . '/Pages/admin/dashboard/index.php';
    $csrf = new CsrfTokenManager();
    // Render in its own scope: views assign local variables (one is named
    // $check) that must not overwrite this suite's helpers.
    $renderedDashboard = (static function (array $scope): string {
        extract($scope);
        ob_start();
        require VIEW_PATH . '/Layouts/admin.php';

        return (string) ob_get_clean();
    })(get_defined_vars());
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
    $check(str_contains($renderedDashboard, 'status=INCOMPLETE') && str_contains($renderedDashboard, 'status=OPEN')
        && str_contains($renderedDashboard, 'status=PENDING_VERIFICATION') && str_contains($renderedDashboard, 'stock_status=low')
        && str_contains($renderedDashboard, 'admin/orders/detail.php?id=' . $todayId)
        // The "Today" dashboard links a pending transfer straight to its review action.
        && str_contains($renderedDashboard, 'admin/payments/review.php?id=' . $pendingPaymentId . '&amp;action=verify'), 'Cards and detail links use implemented filtered routes.');
    // The "Today" redesign lists requests as tasks (number, customer, status)
    // without the message text; the message must still never appear unescaped.
    $check(str_contains($renderedDashboard, "DASH-REQ-{$suffix}-") && !str_contains($renderedDashboard, '<script>30 floral'), 'Request tasks render, and request messages never reach the page unescaped.');

    // Simulate an upgrade from the previous schema: balances remain untouched.
    $balancesBefore = $connection->query('SELECT sku, stock_quantity FROM materials ORDER BY id')->fetchAll();
    $connection->exec('ALTER TABLE materials DROP COLUMN minimum_stock_level');
    $connection->exec((string) file_get_contents(__DIR__ . '/../../app/database/migrations/021_add_material_minimum_stock_level.sql'));
    $check(
        $connection->query('SELECT sku, stock_quantity FROM materials ORDER BY id')->fetchAll() === $balancesBefore
            && (int) $connection->query('SELECT COUNT(*) FROM materials WHERE minimum_stock_level IS NOT NULL')->fetchColumn() === 0,
        'Migration preserves existing balances and leaves all old thresholds unconfigured.'
    );
    $connection->exec('SAVEPOINT negative_minimum');
    $rejectedNegative = false;
    try {
        $connection->exec('UPDATE materials SET minimum_stock_level = -0.001');
    } catch (PDOException $exception) {
        $rejectedNegative = $exception->getCode() === '23514';
    }
    $connection->exec('ROLLBACK TO SAVEPOINT negative_minimum');
    $check($rejectedNegative, 'Database constraint also rejects negative thresholds.');
} finally {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    drop_throwaway_auth_schema($connection, $schemaName);
}

foreach ($results as $result) {
    echo $result . PHP_EOL;
}
echo 'Administrator dashboard verification completed without leaving test rows.' . PHP_EOL;
