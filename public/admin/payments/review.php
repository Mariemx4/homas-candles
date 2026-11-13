<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-payments.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = $paymentController->processDecision($_POST, (int) $administrator['id']);
    if ($result['paymentId'] === null) {
        header('Location: ' . BASE_URL . 'admin/payments/index.php', true, 303);
        exit;
    }

    $paymentId = (int) $result['paymentId'];
    if ($result['success']) {
        $authFlash->put(
            'payment_review_notice_' . $result['orderId'],
            [],
            [],
            'Payment ' . (string) $result['orderNumber'] . ' was ' . ($result['action'] === 'verify' ? 'verified' : 'rejected') . '.'
        );
        $location = BASE_URL . 'admin/payments/order.php?' . http_build_query(['order_id' => $result['orderId']]);
        if (($_POST['order_context'] ?? null) === '1') {
            $location = BASE_URL . 'admin/orders/detail.php?' . http_build_query(['id' => $result['orderId']]) . '#order-payments';
        }
    } else {
        $authFlash->put('payment_review_form_' . $paymentId, $result['errors'], $result['values']);
        $location = BASE_URL . 'admin/payments/review.php?' . http_build_query(['id' => $paymentId, 'action' => $result['action']]);
        if (($_POST['order_context'] ?? null) === '1') {
            $location .= '&order_context=1';
        }
    }
    header('Location: ' . $location, true, 303);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}
header('Cache-Control: no-store, private');

$paymentReview = $paymentController->showReview($_GET);
if (is_array($paymentReview['payment']) && !$paymentReview['payment']['validAction']) {
    $paymentReview['httpStatus'] = 400;
}
http_response_code((int) $paymentReview['httpStatus']);
$paymentId = $paymentReview['paymentId'];
$reviewForm = $paymentId !== null
    ? $authFlash->consume('payment_review_form_' . $paymentId)
    : ['errors' => [], 'values' => [], 'success' => null];

$pageTitle = is_array($paymentReview['payment']) ? 'Review payment · ' . $paymentReview['payment']['order_number'] : 'Review payment';
$activeNavigation = 'payments';
$reviewInOrder = ($_GET['order_context'] ?? null) === '1';
$backUrl = is_array($paymentReview['payment'])
    ? BASE_URL . 'admin/payments/order.php?' . http_build_query(['order_id' => $paymentReview['payment']['order_id']])
    : BASE_URL . 'admin/payments/index.php';
if ($reviewInOrder && is_array($paymentReview['payment'])) {
    $backUrl = BASE_URL . 'admin/orders/detail.php?' . http_build_query(['id' => $paymentReview['payment']['order_id']]) . '#order-payments';
}
$breadcrumbs = [
    ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
    ['label' => 'Payments', 'href' => BASE_URL . 'admin/payments/index.php'],
    ['label' => 'Review'],
];
$contentView = VIEW_PATH . '/Pages/admin/payments/review.php';
require VIEW_PATH . '/Layouts/admin.php';
