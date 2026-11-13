<?php

declare(strict_types=1);

use App\Services\OrderEditService;

require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-orders.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}
$orderDetail = $orderController->show($_GET);
http_response_code((int) $orderDetail['httpStatus']);
$backUrl = BASE_URL . 'admin/orders/index.php' . ($orderDetail['backQuery'] !== '' ? '?' . $orderDetail['backQuery'] : '');
$deliveryFeeDefault = \App\Services\Money::formatMillis((require APP_PATH . '/config/checkout.php')['delivery_fee_millis']);
$pageTitle = 'Edit order';
$activeNavigation = 'orders';
$breadcrumbs = [
    ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
    ['label' => 'Orders', 'href' => $backUrl],
    ['label' => 'Edit order'],
];
if (!is_array($orderDetail['order'])) {
    $contentView = VIEW_PATH . '/Pages/admin/orders/detail.php';
} else {
    $order = $orderDetail['order'];
    $query = http_build_query(['id' => $order['id'], 'return' => $orderDetail['backQuery']]);
    $editUrl = BASE_URL . 'admin/orders/edit.php?' . $query;
    $detailUrl = BASE_URL . 'admin/orders/detail.php?' . $query;
    $flashKey = 'order_edit_' . (int) $order['id'];
    if ($method === 'POST') {
        $result = $orderEditController->process((int) $order['id'], $_POST, (int) $administrator['id']);
        if ($result['success']) {
            $authFlash->put('order_edit_notice_' . (int) $order['id'], [], [], 'Your changes have been saved.');
            header('Location: ' . $detailUrl, true, 303);
        } else {
            $authFlash->put($flashKey, $result['errors'], $result['values']);
            header('Location: ' . $editUrl, true, 303);
        }
        exit;
    }
    $editFlash = $authFlash->consume($flashKey);
    $editValues = $editFlash['values'] !== [] ? $editFlash['values']
        : OrderEditService::values($order) + ['version' => OrderEditService::version($order)];
    $canChangeFulfilment = OrderEditService::canChangeFulfilment($order);
    $selectedOrderCustomer = !empty($editValues['customer_id']) && ctype_digit($editValues['customer_id']) ? $orderRepository->findCustomer((int) $editValues['customer_id']) : null;
    $contentView = VIEW_PATH . '/Pages/admin/orders/edit.php';
}
require VIEW_PATH . '/Layouts/admin.php';
