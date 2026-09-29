<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Command-line maintenance tool for Recalculate Prices.
 *
 * DATA FLOW: An operator runs this file from the project root to perform an explicit development or maintenance task outside an HTTP request.
 */

// WHAT: applies the documented pricing rules to every catalogue product once.
// WHY:  run it right after migration 016, so existing products get their
//       calculated prices and cost columns. After that, material, recipe,
//       set-component and product edits reprice automatically; this script is
//       only needed again after changing data outside the admin screens.
//       It changes current catalogue prices only — placed orders keep the
//       prices frozen on their order_items.
//
//       Usage: C:\xampp\php\php.exe scripts\recalculate-prices.php
// SETUP STEP: Load the application code and database connection.
require_once __DIR__ . '/../app/config/database.php';

use App\Database\TransactionManager;
use App\Repositories\PricingRepository;
use App\Services\PricingService;

try {
    $connection = database();
    $pricing = new PricingService(new PricingRepository($connection), new TransactionManager($connection));
    $summary = $pricing->recalculateAll();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Price recalculation failed: ' . $exception->getMessage() . PHP_EOL);
    // RESULT STEP: Return a process status code; zero means success and non-zero lets automation detect failure.
    exit(1);
}

echo "Products updated: {$summary['updated']}" . PHP_EOL;
// Products the rules cannot price keep their previous price; list them so the
// missing recipe or components can be added from the admin screens.
foreach ($summary['unpriced'] as $productId => $issue) {
    echo "[NOT PRICED] product #{$productId}: {$issue}" . PHP_EOL;
}
echo $summary['unpriced'] === [] ? 'Every product is priced by the rules.' . PHP_EOL : '';
