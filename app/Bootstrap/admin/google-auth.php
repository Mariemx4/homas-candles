<?php

declare(strict_types=1);

require_once __DIR__ . '/administrator-auth.php';

$googleConfig = require APP_PATH . '/config/google-auth.php';
$googleOAuthSession = new \App\Auth\GoogleOAuthSession();
$administratorGoogleController = new \App\Auth\AdministratorGoogleController(
    new \App\Auth\GoogleOidcClient($googleConfig, new \App\Http\CurlJsonHttpClient(), STORAGE_PATH . '/cache/google-jwks.json'),
    $googleOAuthSession,
    $administratorAuth,
    $csrf,
    $authFlash,
    $loginThrottle,
    BASE_URL
);
