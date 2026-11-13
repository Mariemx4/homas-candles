<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap/google-auth.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}
if ($method === 'POST') {
    $googleSignInController->completion($_POST);
}
$pending = $googleOAuthSession->pending();
if ($pending === null || $pending['existing_customer'] !== null) {
    $authFlash->put('login', ['form' => \App\Services\Locale::text('google.expired')]);
    header('Location: ' . BASE_URL . 'login.php', true, 303);
    exit;
}
$identity = $pending['identity'];
$formState = $authFlash->consume('google_complete');
$errors = $formState['errors'];
$values = array_replace(['first_name' => $identity->firstName, 'last_name' => $identity->lastName], $formState['values']);
$pageTitle = \App\Services\Locale::text('google.complete_title') . " | Hom'As Candles";
require VIEW_PATH . '/Pages/google-complete.php';
