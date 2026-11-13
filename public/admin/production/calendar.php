<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-production.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
$productionCalendar = $productionController->calendar($_GET);
http_response_code((int) $productionCalendar['httpStatus']);

$pageTitle = 'Production calendar';
$activeNavigation = 'production';
$activeProductionTab = 'calendar';
$breadcrumbs = [
    ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
    ['label' => 'Production'],
];
$contentView = VIEW_PATH . '/Pages/admin/production/calendar.php';
require VIEW_PATH . '/Layouts/admin.php';
