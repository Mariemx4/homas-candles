<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-payments.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Method not allowed.');
}

$resolved = $paymentController->resolveEvidence($_GET);
if (!$resolved['allowed']) {
    http_response_code(404);
    header('Cache-Control: no-store, private');
    exit('Evidence not found.');
}

$absolutePath = $resolved['absolutePath'];
$size = filesize($absolutePath);
if ($size === false) {
    http_response_code(404);
    header('Cache-Control: no-store, private');
    exit('Evidence not found.');
}
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: sandbox; default-src 'none'");
header('Content-Type: ' . $resolved['mimeType']);
header('Content-Disposition: inline; filename="' . $resolved['filename'] . '"');
header('Content-Length: ' . (string) $size);
readfile($absolutePath);
