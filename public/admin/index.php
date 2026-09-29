<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Administrator HTTP entry point for Index.
 *
 * DATA FLOW: The web server invokes this file for a request. It loads bootstrap wiring, enforces administrator access, invokes a controller, and then redirects or selects a page view and layout.
 */

// STEP 1: Load dependency wiring. This creates variables such as
// `$administratorAuthorization` and `$adminDashboardController`.
require_once __DIR__ . '/../../app/Bootstrap/admin/admin-dashboard.php';

// STEP 2: Reject unauthenticated/inactive administrators. On success, the
// returned account array is later used by the layout and dashboard greeting.
$administrator = $administratorAuthorization->requireActiveAdministrator();

// STEP 3: Ask the controller for view-ready data. No HTML is produced yet.
$dashboard = $adminDashboardController->overview();

// STEP 4: Configure the shared layout. `$contentView` tells the layout which
// inner page fragment it should require inside <main>.
$pageTitle = 'Dashboard';
// RENDER STEP: Select the inner page template; the layout will require this path inside its shared HTML shell.
$contentView = __DIR__ . '/../../app/Views/Pages/admin-dashboard.php';

// STEP 5: Render the outer HTML. The layout defines `$escape`, renders shared
// navigation/header elements, and requires `$contentView` in the same scope.
require __DIR__ . '/../../app/Views/Layouts/admin.php';
  
