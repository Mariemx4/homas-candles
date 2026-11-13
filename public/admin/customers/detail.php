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
$customerResult = $adminCustomerController->show($_GET);
http_response_code($customerResult['httpStatus']);
$customerDetail = $customerResult['detail'];
$customerProfileNotice = is_array($customerDetail) ? $authFlash->consume('customer_profile_notice_' . (int)$customerDetail['customer']['id']) : ['success' => null];
$customerName = is_array($customerDetail)
    ? trim((string) $customerDetail['customer']['first_name'] . ' ' . (string) $customerDetail['customer']['last_name'])
    : 'Customer details';
$backUrl = BASE_URL . 'admin/customers/index.php'
    . ($customerResult['backQuery'] !== '' ? '?' . $customerResult['backQuery'] : '');

$pageTitle = $customerName;
$activeNavigation = 'customers';
$breadcrumbs = [
    ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
    ['label' => 'Customers', 'href' => $backUrl],
    ['label' => $customerName],
];
$contentView = VIEW_PATH . '/Pages/admin/customers/detail.php';
require VIEW_PATH . '/Layouts/admin.php';
