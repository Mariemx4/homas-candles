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
    $authFlash->put('order_trash_notice', $result['success'] ? [] : ['form' => $result['message']], [], $result['success'] ? $result['message'] : null);
    header('Location: ' . BASE_URL . 'admin/orders/trash.php', true, 303);
    exit;
}
$orderDetail = $orderController->show($_GET);
http_response_code($orderDetail['httpStatus']);
$backUrl = BASE_URL . 'admin/orders/index.php';
$pageTitle = 'Delete order';
$activeNavigation = 'orders';
$contentView = __DIR__ . '/../../../app/Views/Pages/admin-order/' . (is_array($orderDetail['order']) ? 'admin-order-delete.php' : 'admin-order-detail.php');
require __DIR__ . '/../../../app/Views/Layouts/admin.php';
