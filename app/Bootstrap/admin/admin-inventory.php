<?php

declare(strict_types=1);



require_once __DIR__ . '/administrator-auth.php';

use App\Controllers\MaterialController;
use App\Controllers\ProductRecipeController;
use App\Repositories\MaterialMovementRepository;
use App\Repositories\MaterialRepository;
use App\Repositories\ProductRecipeRepository;
use App\Services\MaterialService;
use App\Services\ProductRecipeService;

$inventoryTransactions = $shared->transactionManager();
// Create PricingService, the single authority for catalogue selling prices.
// Material-cost and recipe changes reprice products inside their own transaction.
$pricingService = $shared->pricingService();
// Create ProductReadinessService so cost and recipe changes can never leave
// an active product incomplete: direct breaking edits are rejected, dependants return to draft.
$productReadinessService = $shared->productReadinessService();
$materialRepository = new MaterialRepository($databaseConnection);
$materialMovementRepository = new MaterialMovementRepository($databaseConnection);
$materialStockRepository = $shared->materialStockRepository();
$materialService = new MaterialService(
    $materialRepository,
    $materialMovementRepository,
    $materialStockRepository,
    $inventoryTransactions,
    $productReadinessService
);
$materialController = new MaterialController($materialService, $csrf);

$productRecipeRepository = new ProductRecipeRepository($databaseConnection);
$productRecipeService = new ProductRecipeService($productRecipeRepository, $pricingService, $productReadinessService);
$productRecipeController = new ProductRecipeController($productRecipeService, $csrf);
