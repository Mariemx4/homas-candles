<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Composition root for Contact; it creates and connects this feature's repositories, services, and controllers.
 *
 * DATA FLOW: A public HTTP entry point requires this file first, then uses the objects exposed here. It should wire dependencies, not render HTML or contain business rules.
 */

require_once __DIR__ . '/session.php';
// product.php loads the database connection and exposes $productService.
require_once __DIR__ . '/product.php';

use App\Database\TransactionManager;
use App\Repositories\CustomRequestRepository;
use App\Repositories\OrderRepository;
use App\Security\ContactAbuseGuard;
use App\Services\ContactRequestService;

$contactSettings = require __DIR__ . '/../config/contact.php';
$databaseConnection = database();
// DEPENDENCY PURPOSE: Create ContactAbuseGuard to reject bots and rapid repeat submissions before any database work.
$contactGuard = new ContactAbuseGuard($contactSettings);
// DEPENDENCY PURPOSE: Create ContactRequestService to apply this feature’s business and validation rules.
$contactService = new ContactRequestService(
    new CustomRequestRepository($databaseConnection),
    new OrderRepository($databaseConnection),
    $productService,
    new TransactionManager($databaseConnection),
    $contactSettings
);
