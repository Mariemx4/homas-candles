<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap/autoload.php';

use App\Controllers\ProductController;
use App\Repositories\ProductRepository;
use App\Services\ProductService;
use App\Services\PublicAssetPathResolver;

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$controller = new ProductController(new ProductService(
    new ProductRepository($db),
    new PublicAssetPathResolver(PUBLIC_PATH, PRODUCT_IMAGE_PATH)
));

foreach (['', '../private', 'missing product'] as $slug) {
    $view = $controller->show($slug);
    if ($view['httpStatus'] !== 404 || $view['product'] !== null || $view['productLoadError'] !== null) {
        throw new RuntimeException('Invalid product slugs must resolve to 404.');
    }
}

// A valid slug reaches the repository. With no product table, the query fails
// and must be classified as a temporary service failure, never as not found.
$failed = $controller->show('valid-slug');
if ($failed['httpStatus'] !== 503 || $failed['product'] !== null || $failed['productLoadError'] === null) {
    throw new RuntimeException('Product backend failures must resolve to 503.');
}

echo "Product response status checks passed.\n";
