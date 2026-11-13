<?php

declare(strict_types=1);



require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/../config/database.php';

use App\Controllers\ProductController;
use App\Repositories\ProductRepository;
use App\Services\ProductService;
use App\Services\PublicAssetPathResolver;

$shared = \App\Container\SharedServices::for(database());
$productRepository = new ProductRepository(database());
// Image paths are checked against PRODUCT_IMAGE_PATH from app/config/paths.php.
$productImages = new PublicAssetPathResolver(PUBLIC_PATH, PRODUCT_IMAGE_PATH);
// Create ScentAvailabilityService so scents follow the stock of their fragrance material.
$scentAvailabilityService = $shared->scentAvailabilityService();
$productService = new ProductService($productRepository, $productImages, $scentAvailabilityService);
$productController = new ProductController($productService);
