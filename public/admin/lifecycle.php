<?php

declare(strict_types=1);
require_once __DIR__ . '/../../app/Bootstrap/admin/admin-lifecycle.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
header('Cache-Control: no-store, private');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET','POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}
$identity = \App\Controllers\AdminLifecycleController::identity($method === 'POST' ? $_POST : $_GET);
if (!$identity) {
    http_response_code(400);
    exit('Select a valid record.');
}
[$kind,$id] = $identity;
$controller = new \App\Controllers\AdminLifecycleController($lifecycleService, $csrf);
if ($method === 'POST') {
    $result = $controller->process($_POST, (int)$administrator['id']);
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => $result['success'],'message' => $result['success'] ? $result['notice'] : implode(' ', $result['errors']), 'csrf_token' => $csrf->token(), 'removed' => $result['success'] && ($_POST['action'] ?? '') === 'delete', 'record_id' => $id, 'redirect_url' => BASE_URL . \App\Http\AdminRecordNavigation::listPath($kind)]);
        exit;
    }
    $authFlash->put('lifecycle', $result['errors'], [], $result['success'] ? $result['notice'] : null);
    header('Location: ' . BASE_URL . 'admin/lifecycle.php?' . http_build_query(['kind' => $kind,'id' => $id]), true, 303);
    exit;
}
$lifecycleFlash = $authFlash->consume('lifecycle');
try {
    $lifecycle = $lifecycleService->detail($kind, $id, (int)$administrator['id']);
} catch (\DomainException $e) {
    $lifecycle = null;
    if (!$lifecycleFlash['success']) {
        $lifecycleFlash['errors']['form'] = $e->getMessage();
        http_response_code(404);
    }
} catch (Throwable) {
    $lifecycle = null;
    $lifecycleFlash['errors']['form'] = 'Record management is temporarily unavailable.';
    http_response_code(503);
}
$pageTitle = 'Manage record';
$activeNavigation = match($kind) {
    'customers' => 'customers','administrators' => 'accounts','materials' => 'inventory','requests' => 'requests',default => 'catalogue'
};
$breadcrumbs = [];
$contentView = VIEW_PATH . '/Pages/admin/records/manage.php';
require VIEW_PATH . '/Layouts/admin.php';
