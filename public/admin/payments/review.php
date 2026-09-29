<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Administrator HTTP entry point for Review.
 *
 * DATA FLOW: The web server invokes this file for a request. It loads bootstrap wiring, enforces administrator access, invokes a controller, and then redirects or selects a page view and layout.
 */

// ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-payments.php';
// ROUTE STEP: Stop or redirect unless the session belongs to an active administrator; keep the returned account for the layout.
$administrator = $administratorAuthorization->requireActiveAdministrator();

// REQUEST BRANCH: POST represents a state-changing form submission, so validate security/input before calling its service action.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = $paymentController->processDecision($_POST, (int) $administrator['id']);
    if ($result['paymentId'] === null) {
        // RESPONSE STEP: Redirect after processing so browser refresh repeats a GET rather than submitting the form again.
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
        // URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
        $location = BASE_URL . 'admin/payments/order.php?' . http_build_query(['order_id' => $result['orderId']]);
    } else {
        $authFlash->put('payment_review_form_' . $paymentId, $result['errors'], $result['values']);
        // URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
        $location = BASE_URL . 'admin/payments/review.php?' . http_build_query(['id' => $paymentId, 'action' => $result['action']]);
    }
    // RESPONSE STEP: Redirect after processing so browser refresh repeats a GET rather than submitting the form again.
    header('Location: ' . $location, true, 303);
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
$backUrl = is_array($paymentReview['payment'])
    // URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
    ? BASE_URL . 'admin/payments/order.php?' . http_build_query(['order_id' => $paymentReview['payment']['order_id']])
    : BASE_URL . 'admin/payments/index.php';
$breadcrumbs = [
    ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
    ['label' => 'Payments', 'href' => BASE_URL . 'admin/payments/index.php'],
    ['label' => 'Review'],
];
// RENDER STEP: Select the inner page template; the layout will require this path inside its shared HTML shell.
$contentView = __DIR__ . '/../../../app/Views/Pages/payment/admin-payment-review.php';

// RENDER STEP: Load the shared layout last, after all variables required by the selected page view have been prepared.
require __DIR__ . '/../../../app/Views/Layouts/admin.php';
