<?php

declare(strict_types=1);

// Logout intentionally has no GET view. A state-changing sign-out must be a
// CSRF-protected POST, so following a plain link can never end a session.
require_once __DIR__ . '/../app/Bootstrap/customer-auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

$customerAuthController->logout($_POST);
