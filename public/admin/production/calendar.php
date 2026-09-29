<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Administrator HTTP entry point for Calendar.
 *
 * DATA FLOW: The web server invokes this file for a request. It loads bootstrap wiring, enforces administrator access, invokes a controller, and then redirects or selects a page view and layout.
 */

// ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-production.php';
// ROUTE STEP: Stop or redirect unless the session belongs to an active administrator; keep the returned account for the layout.
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
// RENDER STEP: Select the inner page template; the layout will require this path inside its shared HTML shell.
$contentView = __DIR__ . '/../../../app/Views/Pages/admin-production/admin-production-calendar.php';

// RENDER STEP: Load the shared layout last, after all variables required by the selected page view have been prepared.
require __DIR__ . '/../../../app/Views/Layouts/admin.php';
