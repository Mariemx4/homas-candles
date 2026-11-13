<?php

declare(strict_types=1);



require_once __DIR__ . '/administrator-auth.php';

use App\Controllers\ProductionController;
use App\Repositories\ProductionRepository;
use App\Services\ProductionService;

$productionRepository = new ProductionRepository($databaseConnection);
$productionService = new ProductionService($productionRepository);
$productionController = new ProductionController($productionService);
