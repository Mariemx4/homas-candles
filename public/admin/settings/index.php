<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-settings.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Method not allowed.');
}
header('Cache-Control: no-store, private');
$settingsCapabilities = $adminSettingsController->index();
$pageTitle = 'Settings';
$activeNavigation = 'settings';
$breadcrumbs = [
    ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
    ['label' => 'Settings'],
];
$contentView = VIEW_PATH . '/Pages/admin/settings/index.php';
require VIEW_PATH . '/Layouts/admin.php';
