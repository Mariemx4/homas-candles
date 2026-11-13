<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-custom-requests.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
header('Cache-Control: no-store, private');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (($_POST['request_payload_complete'] ?? null) !== '1') {
        $_POST['request_payload_error'] = 'incomplete';
    }
    if (($_POST['conversion_action'] ?? '') === 'preview') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($customRequestController->previewConversion($_POST));
        exit;
    }
    $result = $customRequestController->processConversion($_POST, (int) $administrator['id']);
    if ($result['requestId'] === null) {
        header('Location: ' . BASE_URL . 'admin/requests/index.php', true, 303);
        exit;
    }
    $requestId = (int) $result['requestId'];
    if ($result['success']) {
        $authFlash->put('order_transition_notice_' . (int) $result['orderId'], [], [], 'Manual order ' . $result['orderNumber'] . ' created from the custom request.');
        header('Location: ' . BASE_URL . 'admin/orders/detail.php?' . http_build_query(['id' => $result['orderId']]), true, 303);
        exit;
    }
    $authFlash->put('custom_request_conversion_' . $requestId, $result['errors'], $result['values'], !empty($result['reviewed']) ? 'Quotation reviewed. Check the totals below, then create the order.' : null);
    header('Location: ' . BASE_URL . 'admin/requests/convert.php?' . http_build_query(['id' => $requestId]), true, 303);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}
$requestResult = $customRequestController->show($_GET);
$requestDetail = $requestResult['detail'];
if (is_array($requestDetail) && (!empty($requestDetail['request']['archived_at']) || (string) $requestDetail['request']['request_status'] !== 'AGREED' || $requestDetail['request']['converted_order_id'] !== null)) {
    $requestResult['httpStatus'] = 409;
}
http_response_code($requestResult['httpStatus']);
$requestId = is_array($requestDetail) ? (int) $requestDetail['request']['id'] : 0;
$conversionFlash = $requestId > 0 ? $authFlash->consume('custom_request_conversion_' . $requestId) : ['errors' => [], 'values' => [], 'success' => null];
$conversionValues = $conversionFlash['values'];
if ($conversionValues === [] && is_array($requestDetail)) {
    $conversionValues = $customRequestController->conversionDefaults($requestDetail['request']);
}
$minimumQuantity = is_array($requestDetail)
    ? $customRequestConversionService->minimumQuantity($requestDetail['request']) : 1;
try {
    $conversionPricing = is_array($requestDetail) && $requestResult['httpStatus'] === 200
        ? $customRequestConversionService->quote($requestId, $conversionValues) : ['success' => false,'errors' => []];
} catch (Throwable) {
    $conversionPricing = ['success' => false,'errors' => ['form' => 'The quotation is temporarily unavailable.']];
}
$requestNumber = is_array($requestDetail) ? (string) $requestDetail['request']['request_number'] : 'Convert request';
$pageTitle = 'Convert ' . $requestNumber;
$activeNavigation = 'requests';
$breadcrumbs = [
    ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
    ['label' => 'Custom Requests', 'href' => BASE_URL . 'admin/requests/index.php'],
    ['label' => $requestNumber, 'href' => BASE_URL . 'admin/requests/detail.php?id=' . $requestId],
    ['label' => 'Convert'],
];
$contentView = VIEW_PATH . '/Pages/admin/requests/convert.php';
require VIEW_PATH . '/Layouts/admin.php';
