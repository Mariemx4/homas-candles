<?php

declare(strict_types=1);



require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/../config/database.php';

use App\Controllers\CatalogueController;
use App\Repositories\ProductRepository;
use App\Services\PublicAssetPathResolver;

$productRepository = new ProductRepository(database());
// Image paths are checked against PRODUCT_IMAGE_PATH from app/config/paths.php.
$productImages = new PublicAssetPathResolver(PUBLIC_PATH, PRODUCT_IMAGE_PATH);
$catalogueController = new CatalogueController($productRepository, $productImages);
