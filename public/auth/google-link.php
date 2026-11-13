<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap/google-auth.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
$customer = $customerSession->requireCustomer();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}
if ($method === 'POST') {
    $googleSignInController->confirmLink($customer, $_POST);
}
$pending = $googleOAuthSession->pending();
if ($pending === null || $pending['existing_customer'] !== $customer) {
    header('Location: ' . BASE_URL . 'account.php', true, 303);
    exit;
}
$identity = $pending['identity'];
$pageTitle = \App\Services\Locale::text('google.link_title') . " | Hom'As Candles";
require VIEW_PATH . '/Pages/google-link.php';
