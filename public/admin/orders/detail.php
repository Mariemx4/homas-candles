<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Administrator HTTP entry point for Detail.
 *
 * DATA FLOW: The web server invokes this file for a request. It loads bootstrap wiring, enforces administrator access, invokes a controller, and then redirects or selects a page view and layout.
 */

// ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-orders.php';
// ROUTE STEP: Stop or redirect unless the session belongs to an active administrator; keep the returned account for the layout.
$administrator = $administratorAuthorization->requireActiveAdministrator();
$orderDetail = $orderController->show($_GET);
$editNotice = is_array($orderDetail['order'])
    ? $authFlash->consume('order_edit_notice_' . (int) $orderDetail['order']['id'])
    : ['success' => null];
http_response_code((int) $orderDetail['httpStatus']);
$orderTransition = is_array($orderDetail['order'])
    ? $orderStatusController->summary($orderDetail['order'])
    : null;
$orderStatusOptions = is_array($orderDetail['order'])
    ? $orderStatusController->selectionOptions($orderDetail['order']) : [];
$transitionNotice = is_array($orderDetail['order'])
    ? $authFlash->consume('order_transition_notice_' . (int) $orderDetail['order']['id'])
    : ['errors' => [], 'values' => [], 'success' => null];
// WHAT: how each item will be produced (catalogue recipe, custom recipe, or
//       missing), plus the recipe form's materials while it is still editable.
// WHY:  preparation is blocked until every item has production data, so the
//       page must show which items need a custom recipe and let one be added.
$orderProduction = is_array($orderDetail['order'])
    ? $orderProductionService->orderProduction((int) $orderDetail['order']['id'], (string) $orderDetail['order']['order_status'])
    : null;
$recipeNotice = is_array($orderDetail['order'])
    ? $authFlash->consume('order_recipe_notice_' . (int) $orderDetail['order']['id'])
    : ['errors' => [], 'values' => [], 'success' => null];

$orderNumber = is_array($orderDetail['order']) ? (string) $orderDetail['order']['order_number'] : 'Order details';
$pageTitle = $orderNumber;
$activeNavigation = 'orders';
$backUrl = BASE_URL . 'admin/orders/index.php';
if ($orderDetail['backQuery'] !== '') {
    $backUrl .= '?' . $orderDetail['backQuery'];
}
$breadcrumbs = [
    ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
    ['label' => 'Orders', 'href' => $backUrl],
    ['label' => $orderNumber],
];
// RENDER STEP: Select the inner page template; the layout will require this path inside its shared HTML shell.
$contentView = __DIR__ . '/../../../app/Views/Pages/admin-order/admin-order-detail.php';

// RENDER STEP: Load the shared layout last, after all variables required by the selected page view have been prepared.
require __DIR__ . '/../../../app/Views/Layouts/admin.php';
