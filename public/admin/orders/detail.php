<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-orders.php';
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-payments.php';
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
$orderPayment = is_array($orderDetail['order']) ? $paymentController->showOrder(['order_id' => (string)$orderDetail['order']['id']]) : null;
$orderReadiness = is_array($orderDetail['order']) ? (new \App\Repositories\ProductionReadinessRepository($databaseConnection))->forOrders([(int)$orderDetail['order']['id']])[(int)$orderDetail['order']['id']] : null;
$orderPaymentForm = is_array($orderDetail['order']) ? $authFlash->consume('payment_record_form_' . (int)$orderDetail['order']['id']) : ['errors' => [],'values' => [],'success' => null];
$orderPaymentNotice = is_array($orderDetail['order']) ? $authFlash->consume('payment_record_notice_' . (int)$orderDetail['order']['id']) : ['success' => null];
if (is_array($orderDetail['order']) && $orderPaymentNotice['success'] === null) {
    $orderPaymentNotice = $authFlash->consume('payment_review_notice_' . (int)$orderDetail['order']['id']);
}

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
$contentView = VIEW_PATH . '/Pages/admin/orders/detail.php';
require VIEW_PATH . '/Layouts/admin.php';
