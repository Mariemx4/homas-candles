<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-production.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
$productionSheet = $productionController->sheet($_GET);
http_response_code((int) $productionSheet['httpStatus']);

$pageTitle = 'Preparation sheet';
$activeNavigation = 'production';
$activeProductionTab = 'sheet';
$breadcrumbs = [
    ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
    ['label' => 'Production'],
];
$contentView = VIEW_PATH . '/Pages/admin/production/sheet.php';
require VIEW_PATH . '/Layouts/admin.php';
