<?php

declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../config/database.php';

use App\Auth\AuthFormFlash;
use App\Auth\CustomerAuthController;
use App\Auth\CustomerAuthService;
use App\Database\TransactionManager;
use App\Repositories\CustomerAccountRepository;

$databaseConnection = database();
$transactions = new TransactionManager($databaseConnection);
$customerAccounts = new CustomerAccountRepository($databaseConnection, $transactions);
$governorates = require __DIR__ . '/../config/governorates.php';
$authFlash = new AuthFormFlash();
$customerAuth = new CustomerAuthService($customerAccounts, $customerSession, $governorates);
$customerAuthController = new CustomerAuthController(
    $customerAuth,
    $customerSession,
    $csrf,
    $authFlash,
    BASE_URL
);
