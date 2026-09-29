<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Composition root for Catalogue; it creates and connects this feature's repositories, services, and controllers.
 *
 * DATA FLOW: A public HTTP entry point requires this file first, then uses the objects exposed here. It should wire dependencies, not render HTML or contain business rules.
 */

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/../config/database.php';

use App\Controllers\CatalogueController;
use App\Repositories\ProductRepository;
use App\Services\PublicAssetPathResolver;

$productRepository = new ProductRepository(database());
// Image paths are checked against PRODUCT_IMAGE_PATH from app/config/paths.php.
$productImages = new PublicAssetPathResolver(PUBLIC_PATH, PRODUCT_IMAGE_PATH);
// DEPENDENCY PURPOSE: Create CatalogueController to provide the public route with its request-facing coordinator.
$catalogueController = new CatalogueController($productRepository, $productImages);
