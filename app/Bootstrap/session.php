<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Composition root for Session; it creates and connects this feature's repositories, services, and controllers.
 *
 * DATA FLOW: A public HTTP entry point requires this file first, then uses the objects exposed here. It should wire dependencies, not render HTML or contain business rules.
 */

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/../config/config.php';

use App\Auth\CustomerSession;
use App\Security\CsrfTokenManager;

$sessionCookiePath = (string) (parse_url(BASE_URL, PHP_URL_PATH) ?: '/');
// VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
$sessionTimeout = filter_var(getenv('CUSTOMER_SESSION_TIMEOUT'), FILTER_VALIDATE_INT);
// DEPENDENCY PURPOSE: Create CustomerSession to read and update this identity’s session state.
$customerSession = new CustomerSession(
    $sessionCookiePath,
    $sessionTimeout !== false && $sessionTimeout > 0 ? $sessionTimeout : 1800
);
// DEPENDENCY PURPOSE: Create CsrfTokenManager to protect state-changing forms from cross-site request forgery.
$csrf = new CsrfTokenManager();
