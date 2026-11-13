<?php

declare(strict_types=1);



// cart.php already loads the session, CSRF manager, product service and cart.
require_once __DIR__ . '/cart.php';

use App\Services\CheckoutService;

$checkoutSettings = require __DIR__ . '/../config/checkout.php';
$governorates = require __DIR__ . '/../config/governorates.php';
$databaseConnection = database();
$shared = \App\Container\SharedServices::for($databaseConnection);
$orderRepository = $shared->orderRepository();
$transactions = $shared->transactionManager();
$checkoutService = new CheckoutService(
    $cartService,
    $orderRepository,
    $transactions,
    $checkoutSettings,
    $governorates,
    null,
    null,
    // Checks accessory availability (direct and inside sets) with the rows locked.
    $shared->accessoryStockService(),
    // Revalidates each chosen scent against its fragrance-material stock (from product.php).
    $scentAvailabilityService,
    $shared->orderProductionRepository()
);
