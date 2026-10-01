<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Administrator HTTP entry point for Convert.
 *
 * DATA FLOW: The web server invokes this file for a request. It loads bootstrap wiring, enforces administrator access, invokes a controller, and then redirects or selects a page view and layout.
 */

// ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-custom-requests.php';
// ROUTE STEP: Stop or redirect unless the session belongs to an active administrator; keep the returned account for the layout.
$administrator = $administratorAuthorization->requireActiveAdministrator();

// REQUEST BRANCH: POST represents a state-changing form submission, so validate security/input before calling its service action.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = $customRequestController->processConversion($_POST, (int) $administrator['id']);
    if ($result['requestId'] === null) {
        // RESPONSE STEP: Redirect after processing so browser refresh repeats a GET rather than submitting the form again.
        header('Location: ' . BASE_URL . 'admin/requests/index.php', true, 303);
        exit;
    }
    $requestId = (int) $result['requestId'];
    if ($result['success']) {
        $authFlash->put('order_transition_notice_' . (int) $result['orderId'], [], [], 'Manual order ' . $result['orderNumber'] . ' created from the custom request.');
        // RESPONSE STEP: Redirect after processing so browser refresh repeats a GET rather than submitting the form again.
        header('Location: ' . BASE_URL . 'admin/orders/detail.php?' . http_build_query(['id' => $result['orderId']]), true, 303);
        exit;
    }
    $authFlash->put('custom_request_conversion_' . $requestId, $result['errors'], $result['values']);
    // RESPONSE STEP: Redirect after processing so browser refresh repeats a GET rather than submitting the form again.
    header('Location: ' . BASE_URL . 'admin/requests/convert.php?' . http_build_query(['id' => $requestId]), true, 303);
    exit;
}
// REQUEST GUARD: Reject HTTP methods this route does not implement instead of handling them accidentally.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}
$requestResult = $customRequestController->show($_GET);
$requestDetail = $requestResult['detail'];
if (is_array($requestDetail) && ((string) $requestDetail['request']['request_status'] !== 'AGREED' || $requestDetail['request']['converted_order_id'] !== null)) {
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
$requestNumber = is_array($requestDetail) ? (string) $requestDetail['request']['request_number'] : 'Convert request';
$pageTitle = 'Convert ' . $requestNumber;
$activeNavigation = 'requests';
$breadcrumbs = [
    ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
    ['label' => 'Custom Requests', 'href' => BASE_URL . 'admin/requests/index.php'],
    ['label' => $requestNumber, 'href' => BASE_URL . 'admin/requests/detail.php?id=' . $requestId],
    ['label' => 'Convert'],
];
// RENDER STEP: Select the inner page template; the layout will require this path inside its shared HTML shell.
$contentView = __DIR__ . '/../../../app/Views/Pages/admin-custom-request/admin-custom-request-convert.php';
// RENDER STEP: Load the shared layout last, after all variables required by the selected page view have been prepared.
require __DIR__ . '/../../../app/Views/Layouts/admin.php';
