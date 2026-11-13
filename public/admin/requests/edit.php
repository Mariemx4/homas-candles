<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-custom-requests.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
header('Cache-Control: no-store, private');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    if (($_POST['request_payload_complete'] ?? null) !== '1') {
        $_POST['request_payload_error'] = 'incomplete';
    }
    $result = $customRequestController->processBriefEdit($_POST, (int) $administrator['id']);
    $id = $result['requestId'];
    if ($id === null) {
        header('Location: ' . BASE_URL . 'admin/requests/index.php', true, 303);
        exit;
    }
    if ($result['success']) {
        $authFlash->put('custom_request_notice_' . $id, [], [], 'Working brief corrected. Previous specifications are retained in Brief revisions.');
        header('Location: ' . BASE_URL . 'admin/requests/detail.php?id=' . $id, true, 303);
        exit;
    }
    $authFlash->put('custom_request_edit_' . $id, $result['errors'], $result['values']);
    header('Location: ' . BASE_URL . 'admin/requests/edit.php?id=' . $id, true, 303);
    exit;
}
if ($method !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}
$requestResult = $customRequestController->show($_GET);
$requestDetail = $requestResult['detail'];
if (is_array($requestDetail) && (!$requestDetail['briefEditable'] || $requestDetail['request']['custom_products'] === [])) {
    $requestResult['httpStatus'] = 409;
}
http_response_code($requestResult['httpStatus']);
$id = (int) ($requestDetail['request']['id'] ?? 0);
$creationFlash = $authFlash->consume('custom_request_edit_' . $id);
$creationValues = $creationFlash['values'];
$creationOptions = $customRequestController->creationOptions();
if ($creationValues === [] && is_array($requestDetail)) {
    $creationValues = ['version' => $requestDetail['briefVersion'], 'custom_products' => $requestDetail['request']['custom_products']];
    foreach ($creationValues['custom_products'] as &$product) {
        foreach (['color','scent'] as $kind) {
            $product[$kind . '_choice'] = match ($product[$kind . '_mode']) {
                'EXISTING' => (string)$product[$kind . '_id'], 'CUSTOM' => '__custom__', default => ''
            };
            $product[$kind . '_custom'] = $product[$kind . '_preferences'];
        }
        foreach ($product['components'] as &$component) {
            $component['custom_name'] = $component['product_name_snapshot'];
            foreach (['color','scent'] as $kind) {
                $component[$kind . '_choice'] = match ($component[$kind . '_mode']) {
                    'EXISTING' => (string)$component[$kind . '_id'], 'CUSTOM' => '__custom__', default => ''
                };
                $component[$kind . '_custom'] = $component[$kind . '_value'];
            }
        }
        unset($component);
    }
    unset($product);
}
$pageTitle = 'Correct working brief';
$activeNavigation = 'requests';
$breadcrumbs = [['label' => 'Custom Requests','href' => BASE_URL . 'admin/requests/index.php'],['label' => 'Correct brief']];
$contentView = VIEW_PATH . '/Pages/admin/requests/edit.php';
require VIEW_PATH . '/Layouts/admin.php';
