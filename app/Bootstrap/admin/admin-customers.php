<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Composition root for Admin Customers; it creates and connects this feature's repositories, services, and controllers.
 *
 * DATA FLOW: A public HTTP entry point requires this file first, then uses the objects exposed here. It should wire dependencies, not render HTML or contain business rules.
 */

require_once __DIR__ . '/administrator-auth.php';

use App\Controllers\AdminCustomerController;
use App\Repositories\AdminCustomerRepository;
use App\Services\AdminCustomerService;

// DEPENDENCY PURPOSE: Create AdminCustomerRepository to give the service a dedicated database-access object.
$adminCustomerRepository = new AdminCustomerRepository($databaseConnection);
// DEPENDENCY PURPOSE: Create AdminCustomerService to apply this feature’s business and validation rules.
$adminCustomerService = new AdminCustomerService($adminCustomerRepository);
// DEPENDENCY PURPOSE: Create AdminCustomerController to provide the public route with its request-facing coordinator.
$adminCustomerController = new AdminCustomerController($adminCustomerService);
