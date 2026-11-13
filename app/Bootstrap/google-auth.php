<?php

declare(strict_types=1);

require_once __DIR__ . '/customer-auth.php';

$googleConfig = require APP_PATH . '/config/google-auth.php';
$googleCustomerService = new \App\Auth\GoogleCustomerService(
    new \App\Repositories\GoogleCustomerRepository($databaseConnection),
    $customerAccounts,
    $transactions,
    new \App\Auth\CustomerRegistrationValidator($governorates)
);
$googleSignInController = new \App\Auth\GoogleSignInController(
    new \App\Auth\GoogleOidcClient($googleConfig, new \App\Http\CurlJsonHttpClient(), STORAGE_PATH . '/cache/google-jwks.json'),
    $googleOAuthSession,
    $googleCustomerService,
    $customerSession,
    $csrf,
    $authFlash,
    $loginThrottle,
    BASE_URL
);
