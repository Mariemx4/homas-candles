<?php

declare(strict_types=1);
require_once __DIR__ . '/customer-account.php';

$db = database();
$shared = \App\Container\SharedServices::for($db);
$transactions = $shared->transactionManager();
$customerOrderService = new \App\Services\CustomerOrderService(
    $db,
    $transactions,
    $shared->orderRepository(),
    $shared->orderProductionRepository(),
    $shared->accessoryStockService(),
    $shared->scentAvailabilityService(),
    require __DIR__ . '/../config/checkout.php'
);
