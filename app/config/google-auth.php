<?php

declare(strict_types=1);

use App\Auth\GoogleOAuthConfig;

try {
    $local = is_file(__DIR__ . '/google-auth.local.php') ? require __DIR__ . '/google-auth.local.php' : [];
} catch (Throwable) {
    // An incomplete private setup must not break ordinary password sign-in or
    // print a parser error that might contain part of a pasted client secret.
    error_log('Google sign-in private configuration could not be loaded.');
    $local = [];
}
$values = [];
foreach (['client_id', 'client_secret', 'redirect_uri'] as $key) {
    $value = getenv('GOOGLE_' . strtoupper($key));
    $values[$key] = is_string($value) && $value !== '' ? $value : (is_array($local) ? ($local[$key] ?? '') : '');
}
return new GoogleOAuthConfig($values, defined('BASE_URL') ? BASE_URL : '/');
