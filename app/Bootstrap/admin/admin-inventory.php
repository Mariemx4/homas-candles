<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Composition root for Admin Inventory; it creates and connects this feature's repositories, services, and controllers.
 *
 * DATA FLOW: A public HTTP entry point requires this file first, then uses the objects exposed here. It should wire dependencies, not render HTML or contain business rules.
 */

require_once __DIR__ . '/administrator-auth.php';

use App\Controllers\MaterialController;
use App\Controllers\ProductRecipeController;
use App\Database\TransactionManager;
use App\Repositories\AdminProductRepository;
use App\Repositories\MaterialMovementRepository;
use App\Repositories\MaterialRepository;
use App\Repositories\MaterialStockRepository;
use App\Repositories\PricingRepository;
use App\Repositories\ProductRecipeRepository;
use App\Services\MaterialService;
use App\Services\PricingService;
use App\Services\ProductReadinessService;
use App\Services\ProductRecipeService;

// DEPENDENCY PURPOSE: Create TransactionManager to commit related database changes together or roll them all back.
$inventoryTransactions = new TransactionManager($databaseConnection);
// DEPENDENCY PURPOSE: Create PricingService, the single authority for catalogue selling prices.
// Material-cost and recipe changes reprice products inside their own transaction.
$pricingService = new PricingService(new PricingRepository($databaseConnection), $inventoryTransactions);
// DEPENDENCY PURPOSE: Create ProductReadinessService so cost and recipe changes can never leave
// an active product incomplete: direct breaking edits are rejected, dependants return to draft.
$productReadinessService = new ProductReadinessService(
    new AdminProductRepository($databaseConnection),
    $pricingService,
    $inventoryTransactions
);
// DEPENDENCY PURPOSE: Create MaterialRepository to give the service a dedicated database-access object.
$materialRepository = new MaterialRepository($databaseConnection);
// DEPENDENCY PURPOSE: Create MaterialMovementRepository to give the service a dedicated database-access object.
$materialMovementRepository = new MaterialMovementRepository($databaseConnection);
// DEPENDENCY PURPOSE: Create MaterialStockRepository to give the service a dedicated database-access object.
$materialStockRepository = new MaterialStockRepository($databaseConnection, $inventoryTransactions);
// DEPENDENCY PURPOSE: Create MaterialService to apply this feature’s business and validation rules.
$materialService = new MaterialService(
    $materialRepository,
    $materialMovementRepository,
    $materialStockRepository,
    $inventoryTransactions,
    $productReadinessService
);
// DEPENDENCY PURPOSE: Create MaterialController to provide the public route with its request-facing coordinator.
$materialController = new MaterialController($materialService, $csrf);

// DEPENDENCY PURPOSE: Create ProductRecipeRepository to give the service a dedicated database-access object.
$productRecipeRepository = new ProductRecipeRepository($databaseConnection);
// DEPENDENCY PURPOSE: Create ProductRecipeService to apply this feature’s business and validation rules.
$productRecipeService = new ProductRecipeService($productRecipeRepository, $pricingService, $productReadinessService);
// DEPENDENCY PURPOSE: Create ProductRecipeController to provide the public route with its request-facing coordinator.
$productRecipeController = new ProductRecipeController($productRecipeService, $csrf);
