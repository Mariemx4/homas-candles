<?php

declare(strict_types=1);



// Load the administrator bootstrap once. It supplies the database connection,
// session/authorization objects, and class autoloader required below.
require_once __DIR__ . '/administrator-auth.php';

use App\Controllers\AdminDashboardController;
use App\Repositories\AdminDashboardRepository;
use App\Services\AdminDashboardService;

// Dependency construction proceeds from the lowest-level database class toward
// the HTTP-facing controller. Each object receives only what it needs.
$adminDashboardRepository = new AdminDashboardRepository($databaseConnection);

// The service uses repository methods to apply dashboard-specific decisions.
$adminDashboardService = new AdminDashboardService($adminDashboardRepository);

// The public entry point calls this controller and receives a safe view model.
$adminDashboardController = new AdminDashboardController($adminDashboardService);
