<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-payments.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = $paymentController->processRecord($_POST, $_FILES['evidence'] ?? null, (int) $administrator['id']);
    if ($result['orderId'] === null) {
        header('Location: ' . BASE_URL . 'admin/payments/index.php', true, 303);
        exit;
    }

    $orderId = (int) $result['orderId'];
    if ($result['success']) {
        $authFlash->put(
            'payment_record_notice_' . $orderId,
            [],
            [],
            'Payment recorded successfully.'
        );
    } else {
        $authFlash->put('payment_record_form_' . $orderId, $result['errors'], $result['values']);
    }
    $destination = ($_POST['order_context'] ?? null) === '1' ? 'admin/orders/detail.php?' . http_build_query(['id' => $orderId]) . '#order-payments' : 'admin/payments/order.php?' . http_build_query(['order_id' => $orderId]);
    header('Location: ' . BASE_URL . $destination, true, 303);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}
header('Cache-Control: no-store, private');

$paymentOrder = $paymentController->showOrder($_GET);
http_response_code((int) $paymentOrder['httpStatus']);
$orderId = $paymentOrder['orderId'];
$recordForm = $orderId !== null
    ? $authFlash->consume('payment_record_form_' . $orderId)
    : ['errors' => [], 'values' => [], 'success' => null];
$recordNotice = $orderId !== null
    ? $authFlash->consume('payment_record_notice_' . $orderId)
    : ['errors' => [], 'values' => [], 'success' => null];
if ($orderId !== null && $recordNotice['success'] === null) {
    $recordNotice = $authFlash->consume('payment_review_notice_' . $orderId);
}

$orderNumber = is_array($paymentOrder['detail'])
    ? (string) $paymentOrder['detail']['order']['order_number']
    : 'Order payments';
$pageTitle = $orderNumber . ' payments';
$activeNavigation = 'payments';
$orderDetailUrl = $orderId !== null
    ? BASE_URL . 'admin/orders/detail.php?' . http_build_query(['id' => $orderId])
    : BASE_URL . 'admin/orders/index.php';
$breadcrumbs = [
    ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
    ['label' => 'Payments', 'href' => BASE_URL . 'admin/payments/index.php'],
    ['label' => $orderNumber],
];
$contentView = VIEW_PATH . '/Pages/admin/payments/order.php';
require VIEW_PATH . '/Layouts/admin.php';
