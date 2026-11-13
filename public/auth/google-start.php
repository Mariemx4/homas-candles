<?php

declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
require_once __DIR__ . '/../../app/Bootstrap/google-auth.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
$googleSignInController->start($_POST);
