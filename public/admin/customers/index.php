<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-customers.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Method not allowed.');
}
header('Cache-Control: no-store, private');
$customerList = $adminCustomerController->index($_GET);
http_response_code((int) $customerList['httpStatus']);

$pageTitle = 'Customers';
$activeNavigation = 'customers';
$breadcrumbs = [
    ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
    ['label' => 'Customers'],
];
$contentView = VIEW_PATH . '/Pages/admin/customers/index.php';
require VIEW_PATH . '/Layouts/admin.php';
