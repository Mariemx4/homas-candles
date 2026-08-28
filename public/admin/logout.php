<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap/administrator-auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$administratorAuthController->logout($_POST);
