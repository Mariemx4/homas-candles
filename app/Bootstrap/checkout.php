<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Composition root for Checkout; it creates and connects this feature's repositories, services, and controllers.
 *
 * DATA FLOW: A public HTTP entry point requires this file first, then uses the objects exposed here. It should wire dependencies, not render HTML or contain business rules.
 */

// cart.php already loads the session, CSRF manager, product service and cart.
require_once __DIR__ . '/cart.php';

use App\Database\TransactionManager;
use App\Repositories\AccessoryStockRepository;
use App\Repositories\OrderRepository;
use App\Services\AccessoryStockService;
use App\Services\CheckoutService;

$checkoutSettings = require __DIR__ . '/../config/checkout.php';
$governorates = require __DIR__ . '/../config/governorates.php';
$databaseConnection = database();
// DEPENDENCY PURPOSE: Create OrderRepository to give the service a dedicated database-access object.
$orderRepository = new OrderRepository($databaseConnection);
// DEPENDENCY PURPOSE: Create TransactionManager to commit related database changes together or roll them all back.
$transactions = new TransactionManager($databaseConnection);
// DEPENDENCY PURPOSE: Create CheckoutService to apply this feature’s business and validation rules.
$checkoutService = new CheckoutService(
    $cartService,
    $orderRepository,
    $transactions,
    $checkoutSettings,
    $governorates,
    null,
    null,
    // Checks accessory availability (direct and inside sets) with the rows locked.
    new AccessoryStockService(new AccessoryStockRepository($databaseConnection), $transactions),
    // Revalidates each chosen scent against its fragrance-material stock (from product.php).
    $scentAvailabilityService,
    new \App\Repositories\OrderProductionRepository($databaseConnection)
);
