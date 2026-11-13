<?php

declare(strict_types=1);



require_once __DIR__ . '/administrator-auth.php';

use App\Controllers\AdminSettingsController;
use App\Services\AdminSettingsService;

// All three paths come from app/config/paths.php; the service checks that
// BACKUP_PATH really sits outside PUBLIC_PATH before reporting it as protected.
$adminSettingsService = new AdminSettingsService(
    PUBLIC_PATH,
    BACKUP_PATH,
    SCRIPTS_PATH . '/backup-database.php'
);
$adminSettingsController = new AdminSettingsController($adminSettingsService);
