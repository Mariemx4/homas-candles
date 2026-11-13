<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../app/Bootstrap/autoload.php';
require_once APP_PATH . '/config/config.php';
$config = require APP_PATH . '/config/google-auth.php';
$checks = [
    'JWT verification dependency installed' => class_exists(\Firebase\JWT\JWT::class),
    'cURL HTTPS transport installed' => extension_loaded('curl'),
    'OpenSSL signature verification installed' => extension_loaded('openssl'),
    'Google client and matching callback configured' => $config->configured(),
];
foreach ($checks as $label => $ok) {
    echo ($ok ? 'PASS: ' : 'NEEDS SETUP: ') . $label . PHP_EOL;
}
if ($config->configured()) {
    echo 'Authorized redirect URI: ' . $config->redirectUri . PHP_EOL;
} else {
    echo 'Copy app/config/google-auth.example.php to google-auth.local.php and enter your OAuth client privately.' . PHP_EOL;
}
// This command never prints the client secret, contacts Google or writes account data.
exit(in_array(false, $checks, true) ? 1 : 0);
