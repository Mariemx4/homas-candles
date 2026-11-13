<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-payments.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Method not allowed.');
}
header('Cache-Control: no-store, private');

$paymentList = $paymentController->index($_GET);
http_response_code((int) $paymentList['httpStatus']);

$pageTitle = 'Payments';
$activeNavigation = 'payments';
$breadcrumbs = [
    ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
    ['label' => 'Payments'],
];
$contentView = VIEW_PATH . '/Pages/admin/payments/index.php';
require VIEW_PATH . '/Layouts/admin.php';
