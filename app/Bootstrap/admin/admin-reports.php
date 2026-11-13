<?php

declare(strict_types=1);



require_once __DIR__ . '/administrator-auth.php';

use App\Controllers\AdminReportController;
use App\Repositories\AdminReportRepository;
use App\Services\AdminReportCsvService;
use App\Services\AdminReportService;

$adminReportRepository = new AdminReportRepository($databaseConnection);
$adminReportService = new AdminReportService($adminReportRepository);
$adminReportCsvService = new AdminReportCsvService();
$adminReportController = new AdminReportController($adminReportService);
