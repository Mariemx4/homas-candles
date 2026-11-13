<?php

declare(strict_types=1);



require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../canonical-origin.php';
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
$adminSessionTimeout = filter_var(getenv('ADMIN_SESSION_TIMEOUT'), FILTER_VALIDATE_INT);
$administratorSession = new AdministratorSession(
    $sessionCookiePath,
    $adminUrl . 'login.php',
    $adminSessionTimeout !== false && $adminSessionTimeout > 0 ? $adminSessionTimeout : 9000
);
$csrf = new CsrfTokenManager(independentForms: true);
$authFlash = new AuthFormFlash();
$databaseConnection = database();
// Repositories and services that several admin areas share, built once per request.
$shared = \App\Container\SharedServices::for($databaseConnection);
$administratorAccounts = new AdministratorAccountRepository($databaseConnection);
$loginThrottle = new LoginThrottle($databaseConnection, require __DIR__ . '/../../config/login-throttle.php');
$administratorAuth = new AdministratorAuthService($administratorAccounts, $administratorSession, $loginThrottle);
$administratorAuthorization = new AdministratorAuthorization(
    $administratorAccounts,
    $administratorSession,
    $adminUrl . 'login.php'
);
$administratorAuthController = new AdministratorAuthController(
    $administratorAuth,
    $administratorSession,
    $csrf,
    $authFlash,
    $adminUrl
);

//http://localhost/homas-candles/public/admin/login.php
