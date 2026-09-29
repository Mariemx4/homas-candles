<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Administrator HTTP entry point for Action.
 *
 * DATA FLOW: The web server invokes this file for a request. It loads bootstrap wiring, enforces administrator access, invokes a controller, and then redirects or selects a page view and layout.
 */

// ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-custom-requests.php';
// ROUTE STEP: Stop or redirect unless the session belongs to an active administrator; keep the returned account for the layout.
$administrator = $administratorAuthorization->requireActiveAdministrator();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
$result = $customRequestController->processAction($_POST, (int) $administrator['id']);
if ($result['requestId'] === null) {
    // RESPONSE STEP: Redirect after processing so browser refresh repeats a GET rather than submitting the form again.
    header('Location: ' . BASE_URL . 'admin/requests/index.php', true, 303);
    exit;
}
$requestId = (int) $result['requestId'];
if ($result['success']) {
    $messages = ['assign' => 'Assignment updated.', 'transition' => 'Request status updated.', 'contact' => 'Contact entry recorded.'];
    $authFlash->put('custom_request_notice_' . $requestId, [], [], $messages[$result['action']] ?? 'Request updated.');
} else {
    $authFlash->put('custom_request_action_' . $requestId, $result['errors'], $result['values']);
}
// RESPONSE STEP: Redirect after processing so browser refresh repeats a GET rather than submitting the form again.
header('Location: ' . BASE_URL . 'admin/requests/detail.php?' . http_build_query(['id' => $requestId]), true, 303);
exit;
