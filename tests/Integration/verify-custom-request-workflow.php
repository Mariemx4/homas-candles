<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Integration verification for Verify Custom Request Workflow.
 *
 * DATA FLOW: This executable test crosses application and persistence boundaries to confirm the feature layers work together.
 */

require_once __DIR__ . '/../../app/config/paths.php';
ini_set('session.save_path', TEST_SESSION_PATH);
// SETUP STEP: Load the application code or delegated test required before this command can run.
require_once __DIR__ . '/../../app/Bootstrap/admin/admin-custom-requests.php';

use App\Controllers\CustomRequestController;
use App\Services\CustomRequestConversionService;
use App\Services\CustomRequestService;

$results = [];
$check = static function (bool $condition, string $message) use (&$results): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $results[] = '[PASS] ' . $message;
};

$suffix = substr((string) hrtime(true), -8);
$administratorIds = [];
$requestIds = [];
$orderIds = [];
$categoryId = (int) $databaseConnection->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
if ($categoryId < 1) {
    throw new RuntimeException('A seeded category is required for custom-request verification.');
}

$statusOf = static function (int $requestId) use ($databaseConnection): array {
    $statement = $databaseConnection->prepare(
        'SELECT request_status, assigned_administrator_id, converted_order_id, converted_at
         FROM custom_product_requests WHERE id = :id'
    );
    $statement->execute(['id' => $requestId]);
    return $statement->fetch() ?: [];
};
$addRequest = static function (string $label, string $status = 'SUBMITTED') use (
    $databaseConnection, $categoryId, $suffix, &$requestIds
): int {
    $statement = $databaseConnection->prepare(
        'INSERT INTO custom_product_requests
            (request_number, category_id, customer_name_snapshot, customer_email_snapshot,
             customer_phone_snapshot, preferred_contact_method, budget, requested_quantity,
             required_date, color_preferences, scent_preferences, packaging_preferences,
             customer_message, request_status)
         VALUES (:number, :category_id, :customer_name, :email, :phone, \'WHATSAPP\',
                 80.000, 2, \'2026-09-15\', :color, :scent, :packaging, :message, :status)'
    );
    $statement->execute([
        'number' => "REQ-M04-{$label}-{$suffix}", 'category_id' => $categoryId,
        'customer_name' => "Customer <{$label}>", 'email' => "private-{$label}@example.test",
        'phone' => '8' . substr($suffix, -7), 'color' => '<script>amber</script>',
        'scent' => str_repeat('scent ', 80), 'packaging' => null,
        'message' => '<img src=x onerror=alert(1)> customer request', 'status' => $status,
    ]);
    $id = (int) $databaseConnection->lastInsertId();
    $requestIds[$label] = $id;
    return $id;
};

try {
    $insertAdmin = $databaseConnection->prepare(
        "INSERT INTO administrators_accounts
            (first_name, last_name, email, phone_number, password_hash, account_status)
         VALUES (:first_name, :last_name, :email, :phone, :password_hash, :status)"
    );
    foreach ([['ACTIVE', 'Active'], ['SUSPENDED', 'Suspended']] as [$status, $name]) {
        $insertAdmin->execute([
            'first_name' => $name, 'last_name' => 'Request Verifier',
            'email' => strtolower($name) . "-request-{$suffix}@example.test",
            'phone' => ($status === 'ACTIVE' ? '6' : '5') . substr($suffix, -7),
            'password_hash' => password_hash('RequestVerifier123!', PASSWORD_DEFAULT), 'status' => $status,
        ]);
        $administratorIds[$status] = (int) $databaseConnection->lastInsertId();
    }
    $activeId = $administratorIds['ACTIVE'];

    $workflowId = $addRequest('WORKFLOW');
    $conversionId = $addRequest('CONVERT', 'AGREED');
    $rollbackId = $addRequest('ROLLBACK', 'AGREED');

    $list = $customRequestService->list([
        'q' => "Customer <WORKFLOW>", 'status' => 'SUBMITTED',
        'category' => (string) $categoryId, 'administrator' => 'unassigned',
        'date_from' => '2026-09-15', 'date_to' => '2026-09-15',
    ], new DateTimeImmutable('2026-08-28 10:00:00'));
    $check($list['total'] === 1 && $list['requests'][0]['id'] === $workflowId, 'Allow-listed request filters and literal search find the intended record.');
    $check(
        !array_key_exists('customer_email_snapshot', $list['requests'][0])
        && !array_key_exists('customer_phone_snapshot', $list['requests'][0]),
        'Inbox rows exclude customer email and phone details.'
    );
    $invalidList = $customRequestService->list([
        'status' => "SUBMITTED' OR 1=1 --", 'category' => '1 UNION SELECT 1',
        'administrator' => 'all; DROP TABLE orders', 'date_from' => 'not-a-date', 'page' => '-2',
    ], new DateTimeImmutable());
    $check($invalidList['filtersAdjusted'] && $invalidList['filters']['status'] === null && $invalidList['page'] === 1, 'Malformed filters and pagination are reset through server-side allow lists.');

    $assigned = $customRequestService->assign($workflowId, $activeId);
    $check($assigned['success'] && (int) $statusOf($workflowId)['assigned_administrator_id'] === $activeId, 'An open request can be assigned to an active administrator.');
    $badAssignment = $customRequestService->assign($workflowId, $administratorIds['SUSPENDED']);
    $check(!$badAssignment['success'] && (int) $statusOf($workflowId)['assigned_administrator_id'] === $activeId, 'Assignment rejects inactive administrators without changing the current owner.');

    $skipped = $customRequestService->transition($workflowId, $activeId, 'AGREED', 'PHONE', 'Crafted skip');
    $check(!$skipped['success'] && $statusOf($workflowId)['request_status'] === 'SUBMITTED', 'Skipped request transitions are rejected server-side.');
    $check($customRequestService->transition($workflowId, $activeId, 'CONTACTED', 'WHATSAPP', 'Initial reply.')['success'], 'SUBMITTED may advance to CONTACTED with an audited contact entry.');
    $check($customRequestService->transition($workflowId, $activeId, 'DISCUSSING', 'WHATSAPP', 'Discussing options.')['success'], 'CONTACTED may advance to DISCUSSING.');
    $check($customRequestService->transition($workflowId, $activeId, 'AGREED', 'WHATSAPP', 'Agreement reached.')['success'], 'DISCUSSING may advance to AGREED.');
    $contactCount = $databaseConnection->prepare('SELECT COUNT(*) FROM request_contact_attempts WHERE request_id = :id');
    $contactCount->execute(['id' => $workflowId]);
    $check((int) $contactCount->fetchColumn() === 3, 'Status-driving customer contacts remain in the protected timeline.');
    $followUpError = $customRequestService->recordContact($workflowId, $activeId, 'PHONE', 'FOLLOW_UP_REQUIRED', 'Later', '', new DateTimeImmutable());
    $check(!$followUpError['success'], 'Follow-up-required contact entries require a future timestamp.');

    $conversionInput = [
        'product_name' => 'Bespoke amber candle', 'quantity' => '2', 'unit_price' => '60.000',
        'customization_notes' => "Color: amber\nScent: cedar", 'delivery_method' => 'DELIVERY',
        'required_date' => '2026-09-15', 'delivery_fee' => '9.000',
        'street_address' => '1 Candle Street', 'delegation' => 'Tunis',
        'governorate' => 'Tunis', 'postal_code' => '1000', 'administrator_notes' => 'Protect this note.',
    ];
    $conversion = $customRequestConversionService->convert($conversionId, $activeId, $conversionInput);
    $check($conversion['success'], 'An agreed request converts successfully.');
    $orderIds[] = (int) $conversion['orderId'];
    $convertedState = $statusOf($conversionId);
    $check(
        $convertedState['request_status'] === 'CONVERTED'
        && (int) $convertedState['converted_order_id'] === (int) $conversion['orderId']
        && $convertedState['converted_at'] !== null,
        'Conversion status, order link, and timestamp are committed together.'
    );
    $order = $databaseConnection->prepare(
        'SELECT order_source, customer_name_snapshot, customer_email_snapshot, customer_phone_snapshot,
                subtotal, delivery_fee, total_amount, required_deposit_amount, administrator_notes
         FROM orders WHERE id = :id'
    );
    $order->execute(['id' => $conversion['orderId']]);
    $orderRow = $order->fetch();
    $check(
        $orderRow['order_source'] === 'MANUAL' && $orderRow['customer_name_snapshot'] === 'Customer <CONVERT>'
        && $orderRow['subtotal'] === '120.000' && $orderRow['total_amount'] === '129.000'
        && $orderRow['required_deposit_amount'] === '51.600',
        'Manual order preserves customer snapshots and calculates exact totals and the 40% deposit server-side.'
    );
    $audit = $databaseConnection->prepare('SELECT COUNT(*) FROM order_status_history WHERE order_id = :id AND previous_status IS NULL AND new_status = \'REQUESTED\' AND changed_by_administrator_id = :administrator_id');
    $audit->execute(['id' => $conversion['orderId'], 'administrator_id' => $activeId]);
    $check((int) $audit->fetchColumn() === 1, 'Conversion creates the initial administrator-attributed order audit entry.');
    $duplicate = $customRequestConversionService->convert($conversionId, $activeId, $conversionInput);
    $check(!$duplicate['success'], 'A repeated conversion attempt cannot create another order.');

    $collisionNumber = 'ORD-M-COLLIDE-' . $suffix;
    $collisionOrder = $databaseConnection->prepare(
        "INSERT INTO orders
            (order_number, language_code, order_source, order_status, delivery_method, required_date,
             customer_name_snapshot, customer_phone_snapshot, subtotal, delivery_fee, total_amount, required_deposit_amount)
         VALUES (:number, 'en', 'MANUAL', 'REQUESTED', 'PICKUP', '2026-09-15', 'Collision', '90000009', 1.000, 0.000, 1.000, 0.000)"
    );
    $collisionOrder->execute(['number' => $collisionNumber]);
    $collisionOrderId = (int) $databaseConnection->lastInsertId();
    $orderIds[] = $collisionOrderId;
    $failingService = new CustomRequestConversionService(
        $customRequestRepository,
        $customRequestTransactions,
        static fn (): string => $collisionNumber
    );
    try {
        $failingService->convert($rollbackId, $activeId, $conversionInput);
        throw new RuntimeException('A duplicate generated order number was unexpectedly accepted.');
    } catch (PDOException) {
        // Expected database failure; the transaction manager must roll it back.
    }
    $rollbackState = $statusOf($rollbackId);
    $check($rollbackState['request_status'] === 'AGREED' && $rollbackState['converted_order_id'] === null, 'A database failure rolls back conversion and leaves the request agreed and unlinked.');

    $badInput = $customRequestConversionService->convert($rollbackId, $activeId, [
        'product_name' => ['not a string'], 'quantity' => '0', 'unit_price' => '1e3',
        'delivery_method' => 'DELIVERY', 'required_date' => '2026-99-99', 'delivery_fee' => '0',
    ]);
    $check(!$badInput['success'] && count($badInput['errors']) >= 4 && $statusOf($rollbackId)['request_status'] === 'AGREED', 'Malformed conversion input is rejected before database mutation.');

    $closedAssignment = $customRequestService->assign($conversionId, null);
    $closedContact = $customRequestService->recordContact($conversionId, $activeId, 'PHONE', 'CONTACTED', '', '', new DateTimeImmutable());
    $check(!$closedAssignment['success'] && !$closedContact['success'], 'Converted requests reject assignment and contact-history edits.');

    $controller = new CustomRequestController($customRequestService, $customRequestConversionService, $csrf);
    $csrfResult = $controller->processAction([
        'csrf_token' => str_repeat('0', 64), 'id' => (string) $rollbackId,
        'action' => 'assign', 'administrator_id' => (string) $activeId,
    ], $activeId);
    $check(!$csrfResult['success'] && $statusOf($rollbackId)['assigned_administrator_id'] === null, 'Invalid CSRF blocks a request mutation before business logic runs.');
    $check(is_array($administratorAuthorization->activeAdministrator($activeId)), 'An active administrator passes the server-side route authorization check.');
    $check($administratorAuthorization->activeAdministrator($administratorIds['SUSPENDED']) === null, 'A suspended administrator fails the server-side route authorization check.');

    $detail = $customRequestService->detail($workflowId, new DateTimeImmutable());
    $requestDetail = $detail;
    $requestResult = ['httpStatus' => 200];
    $backUrl = BASE_URL . 'admin/requests/index.php';
    $actionFlash = ['errors' => [], 'values' => [], 'success' => null];
    $noticeFlash = ['errors' => [], 'values' => [], 'success' => null];
    $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    ob_start();
    // SETUP STEP: Load the application code or delegated test required before this command can run.
    require __DIR__ . '/../../app/Views/Pages/admin-custom-request-detail.php';
    $html = (string) ob_get_clean();
    $check(
        str_contains($html, '&lt;script&gt;amber&lt;/script&gt;')
        && str_contains($html, '&lt;img src=x onerror=alert(1)&gt;')
        && !str_contains($html, '<script>amber</script>'),
        'Snapshot preferences and long customer messages render escaped on detail pages.'
    );
} finally {
    if ($requestIds !== []) {
        $ids = array_values($requestIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $databaseConnection->prepare("DELETE FROM request_contact_attempts WHERE request_id IN ({$placeholders})")->execute($ids);
        $databaseConnection->prepare("DELETE FROM custom_product_requests WHERE id IN ({$placeholders})")->execute($ids);
    }
    if ($orderIds !== []) {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        foreach (['order_status_history', 'order_items'] as $table) {
            $databaseConnection->prepare("DELETE FROM {$table} WHERE order_id IN ({$placeholders})")->execute($orderIds);
        }
        $databaseConnection->prepare("DELETE FROM orders WHERE id IN ({$placeholders})")->execute($orderIds);
    }
    foreach ($administratorIds as $id) {
        $databaseConnection->prepare('DELETE FROM administrators_accounts WHERE id = :id')->execute(['id' => $id]);
    }
}

foreach ($results as $result) {
    echo $result . PHP_EOL;
}
echo 'Custom-request workflow verification completed without leaving test rows.' . PHP_EOL;
