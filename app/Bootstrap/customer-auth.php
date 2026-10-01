<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Composition root for Customer Auth; it creates and connects this feature's repositories, services, and controllers.
 *
 * DATA FLOW: A public HTTP entry point requires this file first, then uses the objects exposed here. It should wire dependencies, not render HTML or contain business rules.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../config/database.php';

use App\Auth\AuthFormFlash;
use App\Auth\CustomerAuthController;
use App\Auth\CustomerAuthService;
use App\Auth\LoginThrottle;
use App\Database\TransactionManager;
use App\Repositories\CustomerAccountRepository;

$databaseConnection = database();
// DEPENDENCY PURPOSE: Create TransactionManager to commit related database changes together or roll them all back.
$transactions = new TransactionManager($databaseConnection);
// DEPENDENCY PURPOSE: Create CustomerAccountRepository to give the service a dedicated database-access object.
$customerAccounts = new CustomerAccountRepository($databaseConnection, $transactions);
$governorates = require __DIR__ . '/../config/governorates.php';
// DEPENDENCY PURPOSE: Create AuthFormFlash to supply one reusable dependency to the objects constructed later.
$authFlash = new AuthFormFlash();
// DEPENDENCY PURPOSE: Create CustomerAuthService to apply this feature’s business and validation rules.
$loginThrottle = new LoginThrottle($databaseConnection, require __DIR__ . '/../config/login-throttle.php');
$customerAuth = new CustomerAuthService($customerAccounts, $customerSession, $governorates, $loginThrottle);
// DEPENDENCY PURPOSE: Create CustomerAuthController to provide the public route with its request-facing coordinator.
$customerAuthController = new CustomerAuthController(
    $customerAuth,
    $customerSession,
    $csrf,
    $authFlash,
    BASE_URL
);
