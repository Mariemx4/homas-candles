<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Unit verification for Verify Base Url.
 *
 * DATA FLOW: This executable test isolates a small behavior and fails with a non-zero result when its contract is broken.
 */

$_SERVER['SCRIPT_NAME'] = '/homas-candles/public/shop.php';
// SETUP STEP: Load the application code or delegated test required before this command can run.
require __DIR__ . '/../../app/config/config.php';

if (BASE_URL !== '/homas-candles/public/') {
    throw new RuntimeException('Unexpected discovered base URL: ' . BASE_URL);
}

echo '[PASS] BASE_URL is discovered for an XAMPP subfolder installation.' . PHP_EOL;
