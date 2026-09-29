<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Composition root for Admin Reports; it creates and connects this feature's repositories, services, and controllers.
 *
 * DATA FLOW: A public HTTP entry point requires this file first, then uses the objects exposed here. It should wire dependencies, not render HTML or contain business rules.
 */

require_once __DIR__ . '/administrator-auth.php';

use App\Controllers\AdminReportController;
use App\Repositories\AdminReportRepository;
use App\Services\AdminReportCsvService;
use App\Services\AdminReportService;

// DEPENDENCY PURPOSE: Create AdminReportRepository to give the service a dedicated database-access object.
$adminReportRepository = new AdminReportRepository($databaseConnection);
// DEPENDENCY PURPOSE: Create AdminReportService to apply this feature’s business and validation rules.
$adminReportService = new AdminReportService($adminReportRepository);
// DEPENDENCY PURPOSE: Create AdminReportCsvService to apply this feature’s business and validation rules.
$adminReportCsvService = new AdminReportCsvService();
// DEPENDENCY PURPOSE: Create AdminReportController to provide the public route with its request-facing coordinator.
$adminReportController = new AdminReportController($adminReportService);
