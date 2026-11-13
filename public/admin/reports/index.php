<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-reports.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Method not allowed.');
}
header('Cache-Control: no-store, private');
$reportResult = $adminReportController->index($_GET);
http_response_code((int) $reportResult['httpStatus']);

$pageTitle = 'Reports';
$activeNavigation = 'reports';
$breadcrumbs = [
    ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
    ['label' => 'Reports'],
];
$contentView = VIEW_PATH . '/Pages/admin/reports/index.php';
require VIEW_PATH . '/Layouts/admin.php';
