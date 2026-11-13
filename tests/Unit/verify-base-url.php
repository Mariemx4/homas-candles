<?php

declare(strict_types=1);



$_SERVER['SCRIPT_NAME'] = '/homas-candles/public/shop.php';
require __DIR__ . '/../../app/config/config.php';

if (BASE_URL !== '/homas-candles/public/') {
    throw new RuntimeException('Unexpected discovered base URL: ' . BASE_URL);
}

echo '[PASS] BASE_URL is discovered for an XAMPP subfolder installation.' . PHP_EOL;
