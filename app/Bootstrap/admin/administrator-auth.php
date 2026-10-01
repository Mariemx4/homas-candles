<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Composition root for Administrator Auth; it creates and connects this feature's repositories, services, and controllers.
 *
 * DATA FLOW: A public HTTP entry point requires this file first, then uses the objects exposed here. It should wire dependencies, not render HTML or contain business rules.
 */

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

use App\Auth\AdministratorAuthController;
use App\Auth\AdministratorAuthService;
use App\Auth\AdministratorAuthorization;
use App\Auth\AdministratorSession;
use App\Auth\AuthFormFlash;
use App\Auth\LoginThrottle;
use App\Repositories\AdministratorAccountRepository;
use App\Security\CsrfTokenManager;

$sessionCookiePath = (string) (parse_url(BASE_URL, PHP_URL_PATH) ?: '/');
$adminUrl = BASE_URL . 'admin/';
// VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
$adminSessionTimeout = filter_var(getenv('ADMIN_SESSION_TIMEOUT'), FILTER_VALIDATE_INT);
// DEPENDENCY PURPOSE: Create AdministratorSession to read and update this identity’s session state.
$administratorSession = new AdministratorSession(
    $sessionCookiePath,
    $adminUrl . 'login.php',
    $adminSessionTimeout !== false && $adminSessionTimeout > 0 ? $adminSessionTimeout : 9000
);
// DEPENDENCY PURPOSE: Create CsrfTokenManager to protect state-changing forms from cross-site request forgery.
$csrf = new CsrfTokenManager();
// DEPENDENCY PURPOSE: Create AuthFormFlash to supply one reusable dependency to the objects constructed later.
$authFlash = new AuthFormFlash();
$databaseConnection = database();
// DEPENDENCY PURPOSE: Create AdministratorAccountRepository to give the service a dedicated database-access object.
$administratorAccounts = new AdministratorAccountRepository($databaseConnection);
// DEPENDENCY PURPOSE: Create AdministratorAuthService to apply this feature’s business and validation rules.
$loginThrottle = new LoginThrottle($databaseConnection, require __DIR__ . '/../../config/login-throttle.php');
$administratorAuth = new AdministratorAuthService($administratorAccounts, $administratorSession, $loginThrottle);
// DEPENDENCY PURPOSE: Create AdministratorAuthorization to enforce access rules on protected routes.
$administratorAuthorization = new AdministratorAuthorization(
    $administratorAccounts,
    $administratorSession,
    $adminUrl . 'login.php'
);
// DEPENDENCY PURPOSE: Create AdministratorAuthController to provide the public route with its request-facing coordinator.
$administratorAuthController = new AdministratorAuthController(
    $administratorAuth,
    $administratorSession,
    $csrf,
    $authFlash,
    $adminUrl
);

//http://localhost/homas-candles/public/admin/login.php
