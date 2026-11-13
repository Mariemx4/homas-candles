<?php

declare(strict_types=1);
require_once __DIR__ . '/admin-catalogue.php';
require_once __DIR__ . '/admin-inventory.php';
$lifecycleRepository = new \App\Repositories\AdminLifecycleRepository($databaseConnection);
$lifecycleService = new \App\Services\AdminLifecycleService($lifecycleRepository, $shared->transactionManager(), $adminProductService, $materialService, $taxonomyService);
