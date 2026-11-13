<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-inventory.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Method not allowed.');
}
header('Cache-Control: no-store, private');

$movementList = $materialController->movementHistory($_GET);
http_response_code((int) $movementList['httpStatus']);

$pageTitle = 'Stock Movements';
$activeNavigation = 'inventory';
$breadcrumbs = [
    ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
    ['label' => 'Inventory', 'href' => BASE_URL . 'admin/inventory/materials.php'],
    ['label' => 'Stock Movements'],
];
$contentView = VIEW_PATH . '/Pages/admin/inventory/movements.php';
require VIEW_PATH . '/Layouts/admin.php';
