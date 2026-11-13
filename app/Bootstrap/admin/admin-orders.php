<?php

declare(strict_types=1);



require_once __DIR__ . '/administrator-auth.php';

use App\Controllers\OrderController;
use App\Controllers\ManualOrderController;
use App\Controllers\OrderItemRecipeController;
use App\Controllers\OrderStatusController;
use App\Repositories\OrderStatusRepository;
use App\Services\OrderService;
use App\Services\ManualOrderService;
use App\Services\OrderStatusService;

$orderRepository = $shared->orderRepository();
$orderService = new OrderService($orderRepository);
$orderController = new OrderController($orderService);
$orderTransactions = $shared->transactionManager();
// Create AccessoryStockService, the one place that checks and moves accessory stock
// (availability on creation, deduction at PREPARED, restoration when an order is cancelled to Trash).
$accessoryStockService = $shared->accessoryStockService();
$orderTrashController = new \App\Controllers\OrderTrashController($orderRepository, $orderTransactions, $csrf, $accessoryStockService);
// Create OrderProductionService, the one place that decides whether order items
// carry production data (catalogue or custom recipe) and that stores custom recipes.
$orderProductionRepository = $shared->orderProductionRepository();
$orderProductionService = $shared->orderProductionService();
$orderItemRecipeController = new OrderItemRecipeController($orderProductionService, $csrf);
$manualOrderCustomers = $shared->customerAccountRepository();
$manualOrderService = new ManualOrderService(
    $orderRepository,
    $orderTransactions,
    $orderProductionRepository,
    $orderProductionService,
    $accessoryStockService,
    // Revalidates each agreed scent against its fragrance-material stock.
    $shared->scentAvailabilityService(),
    customers: $manualOrderCustomers,
    customerModes: $shared->customerModeService()
);
$manualOrderController = new ManualOrderController($manualOrderService, $csrf);
$orderStatusRepository = new OrderStatusRepository($databaseConnection);
$orderEditService = new \App\Services\OrderEditService($orderRepository, $orderStatusRepository, $orderTransactions);
$orderEditController = new \App\Controllers\OrderEditController($orderEditService, $csrf);
$orderMaterialStockRepository = $shared->materialStockRepository();
$orderStatusService = new OrderStatusService(
    $orderStatusRepository,
    $orderMaterialStockRepository,
    $orderTransactions,
    $orderProductionService,
    $accessoryStockService
);
$orderStatusController = new OrderStatusController($orderStatusService, $orderService, $csrf);
