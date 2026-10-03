<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Bootstrap/customer-payments.php';
header('Cache-Control: no-store, private');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) { http_response_code(405); header('Allow: GET, POST'); exit; }
$rawId = $_GET['order_id'] ?? null;
$customerPayment = $paymentController->showCustomerOrder($rawId, $paymentCustomerId, $paymentSessionOrderIds);
http_response_code($customerPayment['httpStatus']);
if ($customerPayment['detail'] !== null) {
    $paymentOrderId = (int) $customerPayment['detail']['order']['id'];
    $paymentUrl = BASE_URL . 'order-payment.php?' . http_build_query(['order_id' => $paymentOrderId]);
    if ($method === 'POST') {
        $result = $paymentController->processCustomerEvidence(array_replace($_POST, ['order_id' => (string) $paymentOrderId]),
            $_FILES['evidence'] ?? null, $paymentCustomerId, $paymentSessionOrderIds);
        $_SESSION['customer_payment_notice_' . $paymentOrderId] = $result['success']
            ? ['success' => 'Payment proof submitted for manual verification. Your payment is not yet verified.', 'errors' => []]
            : ['success' => null, 'errors' => $result['errors']];
        header('Location: ' . $paymentUrl . '&method=D17', true, 303);
        exit;
    }
    if (isset($_GET['evidence'])) {
        $resolved = $paymentController->resolveCustomerEvidence($_GET['evidence'], $paymentOrderId, $paymentCustomerId, $paymentSessionOrderIds);
        if (!$resolved['allowed']) { http_response_code(404); exit('Evidence not found.'); }
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: sandbox; default-src 'none'");
        header('Content-Type: ' . $resolved['mimeType']);
        header('Content-Disposition: inline; filename="payment-evidence"');
        readfile($resolved['absolutePath']);
        exit;
    }
    $paymentNotice = $_SESSION['customer_payment_notice_' . $paymentOrderId] ?? ['success' => null, 'errors' => []];
    unset($_SESSION['customer_payment_notice_' . $paymentOrderId]);
}
$pageTitle = 'Order payment | HOM’AS Candles';
$paymentMethod = ($_GET['method'] ?? '') === 'D17' ? 'D17' : 'CASH_ON_DELIVERY';
require __DIR__ . '/../app/Views/Pages/payment/customer-payment.php';
