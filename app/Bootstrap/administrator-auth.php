<?php

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

use App\Auth\AdministratorAuthController;
use App\Auth\AdministratorAuthService;
use App\Auth\AdministratorAuthorization;  
use App\Auth\AdministratorSession;
use App\Auth\AuthFormFlash;
use App\Repositories\AdministratorAccountRepository;
use App\Security\CsrfTokenManager;

$sessionCookiePath = (string) (parse_url(BASE_URL, PHP_URL_PATH) ?: '/');
$adminUrl = BASE_URL . 'admin/';
$adminSessionTimeout = filter_var(getenv('ADMIN_SESSION_TIMEOUT'), FILTER_VALIDATE_INT);
$administratorSession = new AdministratorSession(
    $sessionCookiePath,
    $adminUrl . 'login.php',
    $adminSessionTimeout !== false && $adminSessionTimeout > 0 ? $adminSessionTimeout : 900
);
$csrf = new CsrfTokenManager();
$authFlash = new AuthFormFlash();
$databaseConnection = database();
$administratorAccounts = new AdministratorAccountRepository($databaseConnection);
$administratorAuth = new AdministratorAuthService($administratorAccounts, $administratorSession);
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
