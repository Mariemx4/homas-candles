<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap/autoload.php';

use App\Services\PublicMetadata;

$originBefore = getenv('APP_PUBLIC_ORIGIN');
putenv('APP_PUBLIC_ORIGIN=https://shop.example.test');
try {
    $server = ['HTTP_HOST' => 'untrusted.example.test'];
    $requestOnly = [
        'slug' => 'bouquet', 'name' => 'Candle Bouquet', 'description' => 'Handmade to order',
        'main_image_path' => 'assets/products/bouquet.jpg', 'purchase_mode' => 'REQUEST_ONLY',
    ];
    $metadata = PublicMetadata::forPage(
        'product.php',
        false,
        'en',
        '/public/',
        $server,
        [],
        200,
        null,
        $requestOnly
    );
    if ($metadata['canonical'] !== 'https://shop.example.test/public/product.php?slug=bouquet&lang=en'
        || $metadata['structuredData']['@type'] !== 'Product'
        || isset($metadata['structuredData']['offers'])) {
        throw new RuntimeException('Request-only products must have a canonical Product without an unconfirmed offer.');
    }

    $missing = PublicMetadata::forPage('product.php', false, 'en', '/public/', $server, [], 404);
    if ($missing['robots'] !== 'noindex, follow' || $missing['canonical'] !== null
        || $missing['structuredData'] !== null) {
        throw new RuntimeException('Missing products must not expose canonical or structured data.');
    }
    $private = PublicMetadata::forPage('account.php', false, 'en', '/public/', $server, [], 200);
    if ($private['robots'] !== 'noindex, nofollow' || $private['canonical'] !== null) {
        throw new RuntimeException('Private pages must be excluded from indexing.');
    }
    $accessory = [
        'slug' => 'accessory', 'name' => 'Accessory', 'description' => 'A small candle accessory',
        'main_image_path' => 'assets/products/accessory.jpg', 'purchase_mode' => 'CART',
        'selling_price' => '12.345', 'category_slug' => 'accessories',
        'minimum_quantity' => 1, 'stock_quantity' => 0, 'is_made_to_order' => 0,
    ];
    $outOfStock = PublicMetadata::forPage(
        'product.php',
        false,
        'fr',
        '/public/',
        $server,
        [],
        200,
        null,
        $accessory
    );
    if ($outOfStock['structuredData']['offers']['price'] !== '12.345'
        || $outOfStock['structuredData']['offers']['availability'] !== 'https://schema.org/OutOfStock') {
        throw new RuntimeException('Product offers must use exact prices and current stock availability.');
    }
    putenv('APP_PUBLIC_ORIGIN');
    if (PublicMetadata::origin($server) !== null) {
        throw new RuntimeException('Untrusted Host headers must not define public canonical URLs.');
    }
} finally {
    putenv($originBefore === false ? 'APP_PUBLIC_ORIGIN' : 'APP_PUBLIC_ORIGIN=' . $originBefore);
}

echo "Public metadata checks passed.\n";
