<?php

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/../config/database.php';

use App\Controllers\ProductController;
use App\Repositories\ProductRepository;
use App\Services\ProductService;
use App\Services\PublicAssetPathResolver;

$productRepository = new ProductRepository(database());
$productImages = new PublicAssetPathResolver(
    dirname(__DIR__, 2) . '/public',
    dirname(__DIR__, 2) . '/public/assets/products'
);
$productService = new ProductService($productRepository, $productImages);
$productController = new ProductController($productService);
