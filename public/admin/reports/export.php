<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-reports.php';
$administratorAuthorization->requireActiveAdministrator();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Method not allowed.');
}
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
$export = $adminReportController->export($_GET);
if ($export['httpStatus'] !== 200) {
    http_response_code((int) $export['httpStatus']);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $export['error'];
    exit;
}

$filters = $export['filters'];
$filename = sprintf('homas-%s-%s-to-%s.csv', $filters['section'], $filters['start'], $filters['end']);
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$output = fopen('php://output', 'wb');
if ($output === false) {
    http_response_code(500);
    exit('The export could not be opened.');
}
$adminReportCsvService->write($output, $export['columns'], $export['rows']);
fclose($output);
