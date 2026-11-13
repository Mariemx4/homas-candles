<?php

declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}
require_once __DIR__ . '/../../app/Bootstrap/session.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
// Session-bound state authorizes the flow. An admin state prefix also keeps an
// expired/lost-state error on staff login; it grants no authentication by itself.
$googleCallbackSession = new \App\Auth\GoogleOAuthSession();
if ($googleCallbackSession->isAdministratorFlow($_GET['state'] ?? null)) {
    require_once __DIR__ . '/../../app/Bootstrap/admin/google-auth.php';
    $administratorGoogleController->callback($_GET);
}
require_once __DIR__ . '/../../app/Bootstrap/google-auth.php';
$googleSignInController->callback($_GET);
