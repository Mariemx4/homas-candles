<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Composition root for Admin Settings; it creates and connects this feature's repositories, services, and controllers.
 *
 * DATA FLOW: A public HTTP entry point requires this file first, then uses the objects exposed here. It should wire dependencies, not render HTML or contain business rules.
 */

require_once __DIR__ . '/administrator-auth.php';

use App\Controllers\AdminSettingsController;
use App\Services\AdminSettingsService;

// DEPENDENCY PURPOSE: Create AdminSettingsService to apply this feature’s business and validation rules.
// All three paths come from app/config/paths.php; the service checks that
// BACKUP_PATH really sits outside PUBLIC_PATH before reporting it as protected.
$adminSettingsService = new AdminSettingsService(
    PUBLIC_PATH,
    BACKUP_PATH,
    SCRIPTS_PATH . '/backup-database.php'
);
// DEPENDENCY PURPOSE: Create AdminSettingsController to provide the public route with its request-facing coordinator.
$adminSettingsController = new AdminSettingsController($adminSettingsService);
