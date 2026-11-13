<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-orders.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
$orderList = $orderController->index($_GET);
foreach ($orderList['orders'] as &$listedOrder) {
    $listedOrder['statusOptions'] = $orderStatusController->selectionOptions($listedOrder);
    $listedOrder['statusReason'] = $orderStatusController->summary($listedOrder)['reason'];
}
unset($listedOrder);
$listStatusNotice = $authFlash->consume('order_status_list_notice');
http_response_code((int) $orderList['httpStatus']);

$pageTitle = 'Orders';
$activeNavigation = 'orders';
$breadcrumbs = [
    ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
    ['label' => 'Orders'],
];
$contentView = VIEW_PATH . '/Pages/admin/orders/index.php';
require VIEW_PATH . '/Layouts/admin.php';
