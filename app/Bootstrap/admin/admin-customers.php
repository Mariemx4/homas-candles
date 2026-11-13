<?php

declare(strict_types=1);



require_once __DIR__ . '/administrator-auth.php';

use App\Controllers\AdminCustomerController;
use App\Repositories\AdminCustomerRepository;
use App\Services\AdminCustomerService;

$adminCustomerRepository = new AdminCustomerRepository($databaseConnection);
$adminCustomerService = new AdminCustomerService($adminCustomerRepository);
$adminCustomerController = new AdminCustomerController($adminCustomerService);
