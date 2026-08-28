<?php

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/../config/config.php';

use App\Auth\CustomerSession;
use App\Security\CsrfTokenManager;

$sessionCookiePath = (string) (parse_url(BASE_URL, PHP_URL_PATH) ?: '/');
$sessionTimeout = filter_var(getenv('CUSTOMER_SESSION_TIMEOUT'), FILTER_VALIDATE_INT);
$customerSession = new CustomerSession(
    $sessionCookiePath,
    $sessionTimeout !== false && $sessionTimeout > 0 ? $sessionTimeout : 1800
);
$csrf = new CsrfTokenManager();
