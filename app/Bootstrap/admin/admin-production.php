<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Composition root for Admin Production; it creates and connects this feature's repositories, services, and controllers.
 *
 * DATA FLOW: A public HTTP entry point requires this file first, then uses the objects exposed here. It should wire dependencies, not render HTML or contain business rules.
 */

require_once __DIR__ . '/administrator-auth.php';

use App\Controllers\ProductionController;
use App\Repositories\ProductionRepository;
use App\Services\ProductionService;

// DEPENDENCY PURPOSE: Create ProductionRepository to give the service a dedicated database-access object.
$productionRepository = new ProductionRepository($databaseConnection);
// DEPENDENCY PURPOSE: Create ProductionService to apply this feature’s business and validation rules.
$productionService = new ProductionService($productionRepository);
// DEPENDENCY PURPOSE: Create ProductionController to provide the public route with its request-facing coordinator.
$productionController = new ProductionController($productionService);
