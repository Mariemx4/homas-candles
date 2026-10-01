<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Bootstrap/catalogue.php';
require_once __DIR__ . '/../app/config/config.php';

use App\Services\CatalogueCategory;
use App\Services\PublicMetadata;

$origin = PublicMetadata::origin($_SERVER);
if ($origin === null) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Configure APP_PUBLIC_ORIGIN before publishing a sitemap.';
    exit;
}

$paths = ['index.php', 'shop.php', 'contact.php'];
foreach ($productRepository->activeCatalogueCategories('en') as $category) {
    try {
        $paths[] = CatalogueCategory::path((string) $category['slug']);
    } catch (InvalidArgumentException) {
        continue;
    }
}
foreach ($productRepository->sitemapProducts() as $product) {
    if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $product['slug']) !== 1
        || $productImages->resolve($product['main_image_path']) === null) {
        continue;
    }
    $paths[] = 'product.php?slug=' . rawurlencode((string) $product['slug']);
}

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($paths as $path) {
    foreach (['en', 'fr', 'ar'] as $languageCode) {
        $url = PublicMetadata::url($origin, BASE_URL, $path, $languageCode);
        echo '  <url><loc>' . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc></url>' . "\n";
    }
}
echo '</urlset>' . "\n";
