<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Administrator HTTP entry point for Detail.
 *
 * DATA FLOW: The web server invokes this file for a request. It loads bootstrap wiring, enforces administrator access, invokes a controller, and then redirects or selects a page view and layout.
 */

// ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-custom-requests.php';
// ROUTE STEP: Stop or redirect unless the session belongs to an active administrator; keep the returned account for the layout.
$administrator = $administratorAuthorization->requireActiveAdministrator();
// REQUEST GUARD: Reject HTTP methods this route does not implement instead of handling them accidentally.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Method not allowed.');
}
$requestResult = $customRequestController->show($_GET);
http_response_code($requestResult['httpStatus']);
$requestDetail = $requestResult['detail'];
$requestId = is_array($requestDetail) ? (int) $requestDetail['request']['id'] : 0;
$actionFlash = $requestId > 0 ? $authFlash->consume('custom_request_action_' . $requestId) : ['errors' => [], 'values' => [], 'success' => null];
$noticeFlash = $requestId > 0 ? $authFlash->consume('custom_request_notice_' . $requestId) : ['errors' => [], 'values' => [], 'success' => null];
$requestNumber = is_array($requestDetail) ? (string) $requestDetail['request']['request_number'] : 'Request details';
$backUrl = BASE_URL . 'admin/requests/index.php' . ($requestResult['backQuery'] !== '' ? '?' . $requestResult['backQuery'] : '');
$pageTitle = $requestNumber;
$activeNavigation = 'requests';
$breadcrumbs = [
    ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
    ['label' => 'Custom Requests', 'href' => $backUrl],
    ['label' => $requestNumber],
];
// RENDER STEP: Select the inner page template; the layout will require this path inside its shared HTML shell.
$contentView = __DIR__ . '/../../../app/Views/Pages/admin-custom-request/admin-custom-request-detail.php';
// RENDER STEP: Load the shared layout last, after all variables required by the selected page view have been prepared.
require __DIR__ . '/../../../app/Views/Layouts/admin.php';
