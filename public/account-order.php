<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Bootstrap/autoload.php';

use App\Http\ErrorBoundary;
use App\Services\Locale;
use App\Services\CustomerOrderService;
use App\Services\Money;

try {
    require_once __DIR__ . '/../app/Bootstrap/session.php';
    $customerId = $customerSession->requireCustomer();
    header('Cache-Control: no-store, private');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET','POST'], true)) {
        http_response_code(405);
        header('Allow: GET, POST');
        exit;
    }
    require_once __DIR__ . '/../app/Bootstrap/customer-account.php';
    $view = $customerAccountController->order($customerId, $_GET['id'] ?? null);
    if ($view['account'] === null) {
        $customerSession->signOut();
        header('Location: ' . BASE_URL . 'login.php', true, 303);
        exit;
    }
    extract($view, EXTR_SKIP);
    $flash = new \App\Auth\AuthFormFlash();
    if ($method === 'POST' && $order !== null) {
        require_once __DIR__ . '/../app/Bootstrap/customer-orders.php';
        $result = ['success' => false,'errors' => ['form' => Locale::text('account.reload')]];
        if ($csrf->validate(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)
            && ($_POST['action'] ?? '') === 'cancel') {
            $result = $customerOrderService->cancel($customerId, (int)$order['id'], is_string($_POST['version'] ?? null) ? $_POST['version'] : '');
        }
        $flash->put('customer_order_' . $order['id'], $result['errors'], [], $result['success'] ? Locale::text('account.cancelled_notice') : null);
        header('Location: ' . BASE_URL . 'account-order.php?id=' . (int)$order['id'], true, 303);
        exit;
    }
    $orderFlash = $flash->consume('customer_order_' . ($order['id'] ?? 0));
    if ($order !== null) {
        $version = CustomerOrderService::version($order, $items);
        $canEdit = CustomerOrderService::editable($order);
        $paid = Money::parseMillis((string)$order['amount_paid']) ?? 0;
        $total = Money::parseMillis($order['total_amount']);
        $paymentFigures = [
            'paid' => Money::formatMillis($paid),
            'pending' => Money::formatMillis(Money::parseMillis((string)$order['pending_amount']) ?? 0),
            'deposit_due' => Money::formatMillis(max(0, Money::parseMillis($order['required_deposit_amount']) - $paid)),
            'remaining' => Money::formatMillis(max(0, $total - $paid)),
            'credit' => Money::formatMillis(max(0, $paid - $total)),
        ];
    }
    if ($order !== null && $order['order_status'] === 'CANCELLED') {
        $paymentFigures['remaining'] = $paymentFigures['deposit_due'] = '0.000';
        $paymentFigures['credit'] = $paymentFigures['paid'];
    }
    if (($_GET['format'] ?? '') === 'json' && $method === 'GET') {
        header('Content-Type: application/json; charset=utf-8');
        if ($order === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Order not found']);
            exit;
        }
        echo json_encode(['version' => $version,'status' => Locale::text('account.status_' . strtolower($order['order_status'])),
            'editable' => $canEdit,'history' => array_map(static fn ($event) => ['status' => Locale::text('account.status_' . strtolower($event['new_status'])),'date' => $event['changed_at']], $history),
            'figures' => $paymentFigures + ['total' => $order['total_amount'],'deposit' => $order['required_deposit_amount']]], JSON_THROW_ON_ERROR);
        exit;
    }
} catch (Throwable $exception) {
    ErrorBoundary::respond($exception, 'customer-order');
}

if ($order === null) {
    http_response_code(404);
}
$pageTitle = Locale::text('account.order') . " | Hom'As Candles";
require VIEW_PATH . '/Pages/customer-order.php';
