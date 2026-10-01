<?php

declare(strict_types=1);

// A stable secret prevents stored bucket hashes from revealing emails, phones or IPs.
$environmentKey = getenv('LOGIN_THROTTLE_KEY');
$local = is_file(__DIR__ . '/login-throttle.local.php')
    ? require __DIR__ . '/login-throttle.local.php'
    : [];
$key = is_string($environmentKey) && $environmentKey !== ''
    ? $environmentKey : (is_array($local) ? ($local['key'] ?? '') : '');
if (!is_string($key) || strlen($key) < 32) {
    throw new RuntimeException('Configure LOGIN_THROTTLE_KEY or app/config/login-throttle.local.php.');
}
return $key;
