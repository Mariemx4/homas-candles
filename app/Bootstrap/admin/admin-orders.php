<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Composition root for Admin Orders; it creates and connects this feature's repositories, services, and controllers.
 *
 * DATA FLOW: A public HTTP entry point requires this file first, then uses the objects exposed here. It should wire dependencies, not render HTML or contain business rules.
 */

require_once __DIR__ . '/administrator-auth.php';

use App\Controllers\OrderController;
use App\Controllers\ManualOrderController;
use App\Controllers\OrderItemRecipeController;
use App\Controllers\OrderStatusController;
use App\Database\TransactionManager;
use App\Repositories\AccessoryStockRepository;
use App\Repositories\CustomerAccountRepository;
use App\Repositories\MaterialStockRepository;
use App\Repositories\OrderProductionRepository;
use App\Repositories\OrderRepository;
use App\Repositories\OrderStatusRepository;
use App\Repositories\ScentStockRepository;
use App\Services\AccessoryStockService;
use App\Services\OrderProductionService;
use App\Services\OrderService;
use App\Services\ManualOrderService;
use App\Services\OrderStatusService;
use App\Services\ScentAvailabilityService;

// DEPENDENCY PURPOSE: Create OrderRepository to give the service a dedicated database-access object.
$orderRepository = new OrderRepository($databaseConnection);
// DEPENDENCY PURPOSE: Create OrderService to apply this feature’s business and validation rules.
$orderService = new OrderService($orderRepository);
// DEPENDENCY PURPOSE: Create OrderController to provide the public route with its request-facing coordinator.
$orderController = new OrderController($orderService);
// DEPENDENCY PURPOSE: Create TransactionManager to commit related database changes together or roll them all back.
$orderTransactions = new TransactionManager($databaseConnection);
// DEPENDENCY PURPOSE: Create AccessoryStockService, the one place that checks and moves accessory stock
// (availability on creation, deduction at PREPARED, restoration when an order is cancelled to Trash).
$accessoryStockService = new AccessoryStockService(new AccessoryStockRepository($databaseConnection), $orderTransactions);
$orderTrashController = new \App\Controllers\OrderTrashController($orderRepository, $orderTransactions, $csrf, $accessoryStockService);
// DEPENDENCY PURPOSE: Create OrderProductionService, the one place that decides whether order items
// carry production data (catalogue or custom recipe) and that stores custom recipes.
$orderProductionRepository = new OrderProductionRepository($databaseConnection);
$orderProductionService = new OrderProductionService($orderProductionRepository, $orderTransactions);
$orderItemRecipeController = new OrderItemRecipeController($orderProductionService, $csrf);
$manualOrderService = new ManualOrderService($orderRepository, $orderTransactions, $orderProductionRepository, $orderProductionService, $accessoryStockService,
    // Revalidates each agreed scent against its fragrance-material stock.
    new ScentAvailabilityService(new ScentStockRepository($databaseConnection), $orderTransactions),
    customers: new CustomerAccountRepository($databaseConnection, $orderTransactions));
$manualOrderController = new ManualOrderController($manualOrderService, $csrf);
// DEPENDENCY PURPOSE: Create OrderStatusRepository to give the service a dedicated database-access object.
$orderStatusRepository = new OrderStatusRepository($databaseConnection);
$orderEditService = new \App\Services\OrderEditService($orderRepository, $orderStatusRepository, $orderTransactions);
$orderEditController = new \App\Controllers\OrderEditController($orderEditService, $csrf);
// DEPENDENCY PURPOSE: Create MaterialStockRepository to give the service a dedicated database-access object.
$orderMaterialStockRepository = new MaterialStockRepository($databaseConnection, $orderTransactions);
// DEPENDENCY PURPOSE: Create OrderStatusService to apply this feature’s business and validation rules.
$orderStatusService = new OrderStatusService(
    $orderStatusRepository,
    $orderMaterialStockRepository,
    $orderTransactions,
    $orderProductionService,
    $accessoryStockService
);
// DEPENDENCY PURPOSE: Create OrderStatusController to provide the public route with its request-facing coordinator.
$orderStatusController = new OrderStatusController($orderStatusService, $orderService, $csrf);
