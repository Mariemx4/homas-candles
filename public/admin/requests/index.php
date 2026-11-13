<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-custom-requests.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Method not allowed.');
}
$requestList = $customRequestController->index($_GET);
http_response_code($requestList['httpStatus']);
header('Cache-Control: no-store, private');
$pageTitle = 'Custom Requests';
$activeNavigation = 'requests';
$breadcrumbs = [
    ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
    ['label' => 'Custom Requests'],
];
$contentView = VIEW_PATH . '/Pages/admin/requests/index.php';
require VIEW_PATH . '/Layouts/admin.php';
