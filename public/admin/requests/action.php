<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-custom-requests.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
$result = $customRequestController->processAction($_POST, (int) $administrator['id']);
if ($result['requestId'] === null) {
    header('Location: ' . BASE_URL . 'admin/requests/index.php', true, 303);
    exit;
}
$requestId = (int) $result['requestId'];
if ($result['success']) {
    $messages = ['create_drafts' => 'Inactive catalogue drafts created. Complete and activate them in Catalogue.', 'assign' => 'Assignment updated.', 'transition' => 'Request status updated.', 'contact' => 'Contact entry recorded.'];
    $authFlash->put('custom_request_notice_' . $requestId, [], [], $messages[$result['action']] ?? 'Request updated.');
} else {
    $authFlash->put('custom_request_action_' . $requestId, $result['errors'], $result['values']);
}
header('Location: ' . BASE_URL . 'admin/requests/detail.php?' . http_build_query(['id' => $requestId]), true, 303);
exit;
