<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-production.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
$productionQueue = $productionController->queue($_GET);
http_response_code((int) $productionQueue['httpStatus']);

$pageTitle = 'Production queue';
$activeNavigation = 'production';
$activeProductionTab = 'queue';
$breadcrumbs = [
    ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
    ['label' => 'Production'],
];
$contentView = VIEW_PATH . '/Pages/admin/production/queue.php';
require VIEW_PATH . '/Layouts/admin.php';
