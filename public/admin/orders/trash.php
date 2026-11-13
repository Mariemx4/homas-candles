<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-orders.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    $result = $orderTrashController->process($_POST, (int) $administrator['id'], true);
    $authFlash->put('order_trash_notice', $result['success'] ? [] : ['form' => $result['message']], [], $result['success'] ? $result['message'] : null);
    header('Location: ' . BASE_URL . 'admin/orders/trash.php', true, 303);
    exit;
}
if ($method !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}
$trashNotice = $authFlash->consume('order_trash_notice');
$page = is_string($_GET['page'] ?? null) ? filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : 1;
try {
    $trash = $orderRepository->trashPage($page ?: 1);
} catch (Throwable) {
    http_response_code(503);
    $trash = ['orders' => [], 'page' => 1, 'pages' => 1, 'total' => 0];
    $trashNotice['errors'] = ['form' => 'Trash is temporarily unavailable. Please try again.'];
}
$pageTitle = 'Order Trash';
$activeNavigation = 'orders';
$contentView = VIEW_PATH . '/Pages/admin/orders/trash.php';
require VIEW_PATH . '/Layouts/admin.php';
