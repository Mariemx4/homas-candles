<?php

declare(strict_types=1);



// STEP 1: Load dependency wiring. This creates variables such as
// `$administratorAuthorization` and `$adminDashboardController`.
require_once __DIR__ . '/../../app/Bootstrap/admin/admin-dashboard.php';

// STEP 2: Reject unauthenticated/inactive administrators. On success, the
// returned account array is later used by the layout and dashboard greeting.
$administrator = $administratorAuthorization->requireActiveAdministrator();

// STEP 3: Ask the controller for view-ready data. No HTML is produced yet.
$dashboard = $adminDashboardController->overview();
http_response_code($dashboard['httpStatus']);
header('Cache-Control: no-store, private');

// STEP 4: Configure the shared layout. `$contentView` tells the layout which
// inner page fragment it should require inside <main>.
$pageTitle = 'Today';
$contentView = VIEW_PATH . '/Pages/admin/dashboard/index.php';

// STEP 5: Render the outer HTML. The layout defines `$escape`, renders shared
// navigation/header elements, and requires `$contentView` in the same scope.
require VIEW_PATH . '/Layouts/admin.php';
