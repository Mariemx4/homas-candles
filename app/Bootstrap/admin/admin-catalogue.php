<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Composition root for Admin Catalogue; it creates and connects this feature's repositories, services, and controllers.
 *
 * DATA FLOW: A public HTTP entry point requires this file first, then uses the objects exposed here. It should wire dependencies, not render HTML or contain business rules.
 */

// Shared administrator setup provides PDO, authorization, CSRF protection, and
// autoloading before feature-specific objects are constructed.
require_once __DIR__ . '/administrator-auth.php';

use App\Controllers\AdminProductController;
use App\Controllers\TaxonomyController;
use App\Database\TransactionManager;
use App\Repositories\AccessoryStockRepository;
use App\Repositories\AdminProductRepository;
use App\Repositories\PricingRepository;
use App\Repositories\TaxonomyRepository;
use App\Services\AccessoryStockService;
use App\Services\AdminProductService;
use App\Services\OpenAiProductTranslationGenerator;
use App\Services\PricingService;
use App\Services\ProductImageStorage;
use App\Services\ProductReadinessService;
use App\Services\TaxonomyService;

// One transaction manager coordinates multi-query catalogue changes so they
// either all commit or all roll back together.
$catalogueTransactions = new TransactionManager($databaseConnection);

// Product administration has its own repository and a file-storage collaborator.
$adminProductRepository = new AdminProductRepository($databaseConnection);
// DEPENDENCY PURPOSE: Create ProductImageStorage to keep file validation and filesystem paths out of controllers.
// PRODUCT_IMAGE_PATH comes from app/config/paths.php, so this bootstrap's own
// folder depth can never change where product photos are written.
$productImageStorage = new ProductImageStorage(PRODUCT_IMAGE_PATH);

// DEPENDENCY PURPOSE: Create PricingService, the single authority for catalogue selling prices.
// It shares the catalogue transaction manager so product edits and repricing commit together.
$pricingService = new PricingService(new PricingRepository($databaseConnection), $catalogueTransactions);
// DEPENDENCY PURPOSE: Create ProductReadinessService, the single authority for activation requirements.
// Built before the taxonomy and product services because both route their changes through it.
$productReadinessService = new ProductReadinessService($adminProductRepository, $pricingService, $catalogueTransactions);

// Taxonomy is the shared name for categories, product types, colors, and scents.
$taxonomyRepository = new TaxonomyRepository($databaseConnection);
// DEPENDENCY PURPOSE: Create TaxonomyService to apply this feature’s business and validation rules.
// Retiring a color or scent returns dependent products to draft through the readiness service.
$taxonomyService = new TaxonomyService($taxonomyRepository, $productReadinessService);
// DEPENDENCY PURPOSE: Create TaxonomyController to provide the public route with its request-facing coordinator.
$taxonomyController = new TaxonomyController($taxonomyService, $csrf);

// Inject dependencies from lower layers into the business service, then inject
// the service and CSRF manager into the HTTP-facing controller.
$translationConfig = is_file(APP_PATH . '/config/translation.local.php')
    ? require APP_PATH . '/config/translation.local.php'
    : [];
$adminProductService = new AdminProductService(
    $adminProductRepository,
    $taxonomyRepository,
    $catalogueTransactions,
    $productImageStorage,
    $pricingService,
    $productReadinessService,
    // Records accessory stock edits (OPENING/ADJUSTMENT) in the stock ledger.
    new AccessoryStockService(new AccessoryStockRepository($databaseConnection), $catalogueTransactions),
    new OpenAiProductTranslationGenerator(
        (string) ($translationConfig['api_key'] ?? ''),
        (string) ($translationConfig['model'] ?? 'gpt-4o-mini')
    )
);
// DEPENDENCY PURPOSE: Create AdminProductController to provide the public route with its request-facing coordinator.
$adminProductController = new AdminProductController($adminProductService, $csrf);
