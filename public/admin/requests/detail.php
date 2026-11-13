<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-custom-requests.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
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
$contentView = VIEW_PATH . '/Pages/admin/requests/detail.php';
require VIEW_PATH . '/Layouts/admin.php';
