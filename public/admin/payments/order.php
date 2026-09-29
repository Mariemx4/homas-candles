<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Administrator HTTP entry point for Order.
 *
 * DATA FLOW: The web server invokes this file for a request. It loads bootstrap wiring, enforces administrator access, invokes a controller, and then redirects or selects a page view and layout.
 */

// ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-payments.php';
// ROUTE STEP: Stop or redirect unless the session belongs to an active administrator; keep the returned account for the layout.
$administrator = $administratorAuthorization->requireActiveAdministrator();

// REQUEST BRANCH: POST represents a state-changing form submission, so validate security/input before calling its service action.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = $paymentController->processRecord($_POST, $_FILES['evidence'] ?? null, (int) $administrator['id']);
    if ($result['orderId'] === null) {
        // RESPONSE STEP: Redirect after processing so browser refresh repeats a GET rather than submitting the form again.
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
    // RESPONSE STEP: Redirect after processing so browser refresh repeats a GET rather than submitting the form again.
    header('Location: ' . BASE_URL . 'admin/payments/order.php?' . http_build_query(['order_id' => $orderId]), true, 303);
    exit;
}

// REQUEST GUARD: Reject HTTP methods this route does not implement instead of handling them accidentally.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}

// RESPONSE POLICY: Tell browsers/proxies how this private or changing response may be cached.
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
    // URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
    ? BASE_URL . 'admin/orders/detail.php?' . http_build_query(['id' => $orderId])
    : BASE_URL . 'admin/orders/index.php';
$breadcrumbs = [
    ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
    ['label' => 'Payments', 'href' => BASE_URL . 'admin/payments/index.php'],
    ['label' => $orderNumber],
];
// RENDER STEP: Select the inner page template; the layout will require this path inside its shared HTML shell.
$contentView = __DIR__ . '/../../../app/Views/Pages/payment/admin-payment-order.php';

// RENDER STEP: Load the shared layout last, after all variables required by the selected page view have been prepared.
require __DIR__ . '/../../../app/Views/Layouts/admin.php';
