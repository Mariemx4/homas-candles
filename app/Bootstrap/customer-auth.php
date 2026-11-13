<?php

declare(strict_types=1);



require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../config/database.php';

use App\Auth\AuthFormFlash;
use App\Auth\CustomerAuthController;
use App\Auth\CustomerAuthService;
use App\Auth\LoginThrottle;

$databaseConnection = database();
$shared = \App\Container\SharedServices::for($databaseConnection);
$transactions = $shared->transactionManager();
$customerAccounts = $shared->customerAccountRepository();
$governorates = require __DIR__ . '/../config/governorates.php';
$authFlash = new AuthFormFlash();
$googleOAuthSession = new \App\Auth\GoogleOAuthSession();
$loginThrottle = new LoginThrottle($databaseConnection, require __DIR__ . '/../config/login-throttle.php');
$customerAuth = new CustomerAuthService($customerAccounts, $customerSession, $governorates, $loginThrottle);
$customerAuthController = new CustomerAuthController(
    $customerAuth,
    $customerSession,
    $csrf,
    $authFlash,
    BASE_URL,
    $googleOAuthSession
);
