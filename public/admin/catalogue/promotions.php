<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-catalogue.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
header('Cache-Control: no-store, private');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = $pricingConfigurationController->save($_POST);
    $authFlash->put('promotion_form', $result['errors'], $result['success'] ? [] : $result['values'], $result['success'] ? 'Promotion saved.' : null);
    $edit = is_string($_POST['edit'] ?? null)
        ? filter_var($_POST['edit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
    $location = BASE_URL . 'admin/catalogue/promotions.php' . ($edit !== false ? '?edit=' . $edit : '');
    header('Location: ' . $location . '#promotion-editor', true, 303);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}
$promotionFlash = $authFlash->consume('promotion_form');
try {
    $promotions = $pricingConfigurationController->promotions();
} catch (Throwable) {
    $promotions = [];
    $promotionFlash['errors']['form'] = 'Promotions are temporarily unavailable. Please try again.';
    http_response_code(503);
}
$editingPromotion = null;
if (isset($_GET['edit']) && is_string($_GET['edit'])) {
    $edit = filter_var($_GET['edit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($edit !== false) {
        foreach ($promotions as $promotion) {
            if ((int) $promotion['id'] !== $edit) {
                continue;
            }
            $editingPromotion = $promotion;
            if (!$promotionFlash['values']) {
                $promotionFlash['values'] = $promotion;
            }
            break;
        }
    }
}
$pageTitle = 'Promotion codes';
$activeNavigation = 'catalogue';
$breadcrumbs = [];
$contentView = VIEW_PATH . '/Pages/admin/catalogue/promotions.php';
require VIEW_PATH . '/Layouts/admin.php';
