<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Administrator HTTP entry point for Evidence.
 *
 * DATA FLOW: The web server invokes this file for a request. It loads bootstrap wiring, enforces administrator access, invokes a controller, and then redirects or selects a page view and layout.
 */

// ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-payments.php';
// ROUTE STEP: Stop or redirect unless the session belongs to an active administrator; keep the returned account for the layout.
$administrator = $administratorAuthorization->requireActiveAdministrator();
// REQUEST GUARD: Reject HTTP methods this route does not implement instead of handling them accidentally.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Method not allowed.');
}

$resolved = $paymentController->resolveEvidence($_GET);
if (!$resolved['allowed']) {
    http_response_code(404);
    // RESPONSE POLICY: Tell browsers/proxies how this private or changing response may be cached.
    header('Cache-Control: no-store, private');
    exit('Evidence not found.');
}

$absolutePath = $resolved['absolutePath'];
$size = filesize($absolutePath);
if ($size === false) {
    http_response_code(404);
    // RESPONSE POLICY: Tell browsers/proxies how this private or changing response may be cached.
    header('Cache-Control: no-store, private');
    exit('Evidence not found.');
}

// RESPONSE POLICY: Tell browsers/proxies how this private or changing response may be cached.
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: sandbox; default-src 'none'");
header('Content-Type: ' . $resolved['mimeType']);
header('Content-Disposition: inline; filename="' . $resolved['filename'] . '"');
header('Content-Length: ' . (string) $size);
readfile($absolutePath);
