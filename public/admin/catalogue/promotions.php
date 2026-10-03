<?php
declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-catalogue.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
header('Cache-Control: no-store, private');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = $pricingConfigurationController->save($_POST);
    $authFlash->put('promotion_form', $result['errors'], $result['success'] ? [] : $result['values'], $result['success'] ? 'Promotion saved.' : null);
    header('Location: ' . BASE_URL . 'admin/catalogue/promotions.php', true, 303); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(405); header('Allow: GET, POST'); exit; }
$promotionFlash = $authFlash->consume('promotion_form');
try { $promotions = $pricingConfigurationController->promotions(); }
catch (Throwable) { $promotions = []; $promotionFlash['errors']['form'] = 'Promotions are temporarily unavailable. Please try again.'; http_response_code(503); }
$pageTitle = 'Promotion codes'; $activeNavigation = 'catalogue'; $breadcrumbs = [];
$contentView = __DIR__ . '/../../../app/Views/Pages/admin-catalogue/admin-catalogue-promotions.php';
require __DIR__ . '/../../../app/Views/Layouts/admin.php';
