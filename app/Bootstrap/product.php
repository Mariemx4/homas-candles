<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Composition root for Product; it creates and connects this feature's repositories, services, and controllers.
 *
 * DATA FLOW: A public HTTP entry point requires this file first, then uses the objects exposed here. It should wire dependencies, not render HTML or contain business rules.
 */

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/../config/database.php';

use App\Controllers\ProductController;
use App\Database\TransactionManager;
use App\Repositories\ProductRepository;
use App\Repositories\ScentStockRepository;
use App\Services\ProductService;
use App\Services\PublicAssetPathResolver;
use App\Services\ScentAvailabilityService;

// DEPENDENCY PURPOSE: Create ProductRepository to give the service a dedicated database-access object.
$productRepository = new ProductRepository(database());
// DEPENDENCY PURPOSE: Create PublicAssetPathResolver to supply one reusable dependency to the objects constructed later.
// Image paths are checked against PRODUCT_IMAGE_PATH from app/config/paths.php.
$productImages = new PublicAssetPathResolver(PUBLIC_PATH, PRODUCT_IMAGE_PATH);
// DEPENDENCY PURPOSE: Create ScentAvailabilityService so scents follow the stock of their fragrance material.
// database() returns the same shared connection on every call.
$scentAvailabilityService = new ScentAvailabilityService(new ScentStockRepository(database()), new TransactionManager(database()));
// DEPENDENCY PURPOSE: Create ProductService to apply this feature’s business and validation rules.
$productService = new ProductService($productRepository, $productImages, $scentAvailabilityService);
// DEPENDENCY PURPOSE: Create ProductController to provide the public route with its request-facing coordinator.
$productController = new ProductController($productService);
