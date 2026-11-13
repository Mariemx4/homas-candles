<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-orders.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}
if ($method === 'POST') {
    $result = $orderTrashController->process($_POST, (int) $administrator['id']);
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        header('Cache-Control: no-store, private');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $result['success'], 'message' => $result['message'], 'csrf_token' => $csrf->token(), 'removed' => $result['success'], 'record_id' => $_POST['id'] ?? '', 'redirect_url' => BASE_URL . 'admin/orders/index.php']);
        exit;
    }
    $authFlash->put('order_trash_notice', $result['success'] ? [] : ['form' => $result['message']], [], $result['success'] ? $result['message'] : null);
    header('Location: ' . BASE_URL . 'admin/orders/trash.php', true, 303);
    exit;
}
$orderDetail = $orderController->show($_GET);
http_response_code($orderDetail['httpStatus']);
$backUrl = BASE_URL . 'admin/orders/index.php';
$pageTitle = 'Delete order';
$activeNavigation = 'orders';
$contentView = VIEW_PATH . '/Pages/admin/orders/' . (is_array($orderDetail['order']) ? 'delete.php' : 'detail.php');
require VIEW_PATH . '/Layouts/admin.php';
