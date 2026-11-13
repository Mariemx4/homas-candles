<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-production.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
$productionSheet = $productionController->sheet($_GET);
http_response_code((int) $productionSheet['httpStatus']);

$pageTitle = 'Preparation sheet '
    . (is_string($productionSheet['dateFrom'] ?? null) ? (string) $productionSheet['dateFrom'] : '');
$contentView = VIEW_PATH . '/Pages/admin/production/sheet-print.php';
require VIEW_PATH . '/Layouts/admin-print.php';
