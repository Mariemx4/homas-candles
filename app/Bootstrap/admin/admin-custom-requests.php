<?php

declare(strict_types=1);



require_once __DIR__ . '/administrator-auth.php';

use App\Controllers\CustomRequestController;
use App\Services\CustomRequestService;
use App\Services\CustomRequestConversionService;

$customRequestRepository = $shared->customRequestRepository();
$customRequestTransactions = $shared->transactionManager();
$customRequestService = new CustomRequestService($customRequestRepository, $customRequestTransactions, $shared->customerModeService());
$customRequestConversionService = new CustomRequestConversionService(
    $customRequestRepository,
    $customRequestTransactions,
    production: $shared->orderProductionService()
);
$customRequestController = new CustomRequestController(
    $customRequestService,
    $customRequestConversionService,
    $csrf
);
