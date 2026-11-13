<?php

declare(strict_types=1);



require_once __DIR__ . '/session.php';
// product.php loads the database connection and exposes $productService.
require_once __DIR__ . '/product.php';

use App\Security\ContactAbuseGuard;
use App\Services\ContactRequestService;

$contactSettings = require __DIR__ . '/../config/contact.php';
$databaseConnection = database();
$shared = \App\Container\SharedServices::for($databaseConnection);
// Create ContactAbuseGuard to reject bots and rapid repeat submissions before any database work.
$contactGuard = new ContactAbuseGuard($contactSettings);
$contactService = new ContactRequestService(
    $shared->customRequestRepository(),
    $shared->orderRepository(),
    $productService,
    $shared->transactionManager(),
    $contactSettings
);
