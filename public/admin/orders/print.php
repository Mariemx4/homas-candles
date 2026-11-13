<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-orders.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
$orderDetail = $orderController->show($_GET);
http_response_code((int) $orderDetail['httpStatus']);

$pageTitle = is_array($orderDetail['order'])
    ? 'Preparation summary ' . (string) $orderDetail['order']['order_number']
    : 'Order summary';
$contentView = VIEW_PATH . '/Pages/admin/orders/print.php';
require VIEW_PATH . '/Layouts/admin-print.php';
