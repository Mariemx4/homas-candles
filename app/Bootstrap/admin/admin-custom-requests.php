<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Composition root for Admin Custom Requests; it creates and connects this feature's repositories, services, and controllers.
 *
 * DATA FLOW: A public HTTP entry point requires this file first, then uses the objects exposed here. It should wire dependencies, not render HTML or contain business rules.
 */

require_once __DIR__ . '/administrator-auth.php';

use App\Controllers\CustomRequestController;
use App\Database\TransactionManager;
use App\Repositories\CustomRequestRepository;
use App\Services\CustomRequestService;
use App\Services\CustomRequestConversionService;

// DEPENDENCY PURPOSE: Create CustomRequestRepository to give the service a dedicated database-access object.
$customRequestRepository = new CustomRequestRepository($databaseConnection);
// DEPENDENCY PURPOSE: Create TransactionManager to commit related database changes together or roll them all back.
$customRequestTransactions = new TransactionManager($databaseConnection);
// DEPENDENCY PURPOSE: Create CustomRequestService to apply this feature’s business and validation rules.
$customRequestService = new CustomRequestService($customRequestRepository, $customRequestTransactions, new \App\Services\CustomerModeService(new \App\Repositories\OrderRepository($databaseConnection), new \App\Repositories\CustomerAccountRepository($databaseConnection, $customRequestTransactions)));
// DEPENDENCY PURPOSE: Create CustomRequestConversionService to apply this feature’s business and validation rules.
$customRequestConversionService = new CustomRequestConversionService(
    $customRequestRepository,
    $customRequestTransactions,
    production: new \App\Services\OrderProductionService(new \App\Repositories\OrderProductionRepository($databaseConnection), $customRequestTransactions)
);
// DEPENDENCY PURPOSE: Create CustomRequestController to provide the public route with its request-facing coordinator.
$customRequestController = new CustomRequestController(
    $customRequestService,
    $customRequestConversionService,
    $csrf
);
