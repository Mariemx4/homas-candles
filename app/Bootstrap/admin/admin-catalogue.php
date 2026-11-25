<?php

declare(strict_types=1);



// Shared administrator setup provides PDO, authorization, CSRF protection, and
// autoloading before feature-specific objects are constructed.
require_once __DIR__ . '/administrator-auth.php';

use App\Controllers\AdminProductController;
use App\Controllers\TaxonomyController;
use App\Repositories\TaxonomyRepository;
use App\Services\AdminProductService;
use App\Services\OpenAiProductTranslationGenerator;
use App\Services\ProductImageStorage;
use App\Services\ProductImageService;
use App\Services\ProductTranslationService;
use App\Services\TaxonomyService;

// One transaction manager coordinates multi-query catalogue changes so they
// either all commit or all roll back together.
$catalogueTransactions = $shared->transactionManager();

// Product administration has its own repository and a file-storage collaborator.
$adminProductRepository = $shared->adminProductRepository();
// Create ProductImageStorage to keep file validation and filesystem paths out of controllers.
// PRODUCT_IMAGE_PATH comes from app/config/paths.php, so this bootstrap's own
// folder depth can never change where product photos are written.
$productImageStorage = new ProductImageStorage(PRODUCT_IMAGE_PATH);

// Create PricingService, the single authority for catalogue selling prices.
// It shares the catalogue transaction manager so product edits and repricing commit together.
$pricingService = $shared->pricingService();
// Create ProductReadinessService, the single authority for activation requirements.
// Built before the taxonomy and product services because both route their changes through it.
$productReadinessService = $shared->productReadinessService();

// Taxonomy is the shared name for categories, product types, colors, and scents.
$taxonomyRepository = new TaxonomyRepository($databaseConnection);
// Retiring a color or scent returns dependent products to draft through the readiness service.
$taxonomyService = new TaxonomyService($taxonomyRepository, $productReadinessService);
$taxonomyController = new TaxonomyController($taxonomyService, $csrf);

// Inject dependencies from lower layers into the business service, then inject
// the service and CSRF manager into the HTTP-facing controller.
$translationConfig = is_file(APP_PATH . '/config/translation.local.php')
    ? require APP_PATH . '/config/translation.local.php'
    : [];
$productOptions = $shared->productOptionRepository();
$productTexts = $shared->productTranslationRepository();
$productImageService = new ProductImageService($adminProductRepository, $productOptions, $catalogueTransactions, $productImageStorage);
$productTranslationService = new ProductTranslationService(
    $adminProductRepository,
    $productTexts,
    $productReadinessService,
    new OpenAiProductTranslationGenerator(
        (string) ($translationConfig['api_key'] ?? ''),
        (string) ($translationConfig['model'] ?? 'gpt-4o-mini')
    )
);
$adminProductService = new AdminProductService(
    $adminProductRepository,
    $productOptions,
    $productTexts,
    $taxonomyRepository,
    $catalogueTransactions,
    $productImageService,
    $pricingService,
    $productReadinessService,
    // Records accessory stock edits (OPENING/ADJUSTMENT) in the stock ledger.
    $shared->accessoryStockService(),
    $productTranslationService
);
// Individual candle prices: suggestions from weight and recipe, and the owner's approvals.
$candlePriceService = new \App\Services\CandlePriceService(new \App\Repositories\CandlePricingRepository($databaseConnection), $catalogueTransactions);
$adminProductController = new AdminProductController(
    $adminProductService, $productTranslationService, $productImageService, $pricingService, $candlePriceService, $csrf
);
$catalogueRecipeService = new \App\Services\ProductRecipeService(new \App\Repositories\ProductRecipeRepository($databaseConnection), $pricingService, $productReadinessService);
$catalogueRecipeController = new \App\Controllers\ProductRecipeController($catalogueRecipeService, $csrf);
$pricingConfigurationController = new \App\Controllers\PricingConfigurationController($pricingService, $csrf);
