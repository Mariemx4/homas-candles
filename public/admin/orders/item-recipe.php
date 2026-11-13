<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-orders.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$result = $orderItemRecipeController->process($_POST);
if ($result['orderId'] < 1) {
    header('Location: ' . BASE_URL . 'admin/orders/index.php', true, 303);
    exit;
}

// detail.php consumes this flash and shows it above the production panel.
$authFlash->put(
    'order_recipe_notice_' . $result['orderId'],
    $result['success'] ? [] : $result['errors'],
    [],
    $result['success'] ? 'Production recipe saved.' : null
);
header('Location: ' . BASE_URL . 'admin/orders/detail.php?' . http_build_query(['id' => $result['orderId']]) . '#order-production-title', true, 303);
exit;
