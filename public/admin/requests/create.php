<?php

declare(strict_types=1);

/**
 * Administrator route for enquiries received outside the website.
 *
 * GET renders the form. POST validates CSRF/input, then redirects so refreshing
 * the browser never submits the same enquiry twice.
 */
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-custom-requests.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (($_POST['action'] ?? '') === 'discard_draft') {
        $valid = $csrf->validate(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null);
        $csrf->rotate();
        if ($valid) {
            \App\Services\AdminWorkDrafts::forget('request', (int)$administrator['id']);
        } else {
            $authFlash->put('custom_request_create', ['form' => 'The form expired. Review the saved draft and try again.'], []);
        }
        header('Location: ' . BASE_URL . 'admin/requests/create.php', true, 303);
        exit;
    }

    if (($_POST['request_payload_complete'] ?? null) !== '1') {
        $_POST['request_payload_error'] = 'incomplete';
    }
    $result = $customRequestController->processCreation($_POST, (int) $administrator['id'], ($_POST['action'] ?? '') === 'save_draft');
    if (!empty($result['draftSaved'])) {
        header('Location: ' . BASE_URL . 'admin/requests/create.php?resume=1', true, 303);
        exit;
    }
    if (is_string($_POST['work_draft_id'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $_POST['work_draft_id'])) {
        $result['values']['work_draft_id'] = $_POST['work_draft_id'];
    }

    if ($result['success']) {
        if (is_string($_POST['work_draft_id'] ?? null)) {
            \App\Services\AdminWorkDrafts::forget('request', (int)$administrator['id'], $_POST['work_draft_id']);
        }
        $authFlash->put('custom_request_notice_' . (int) $result['requestId'], [], [], 'Custom request ' . $result['requestNumber'] . ' created.');
        header('Location: ' . BASE_URL . 'admin/requests/detail.php?' . http_build_query(['id' => $result['requestId']]), true, 303);
        exit;
    }
    $authFlash->put('custom_request_create', $result['errors'], $result['values']);
    header('Location: ' . BASE_URL . 'admin/requests/create.php', true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}

$creationFlash = $authFlash->consume('custom_request_create');
$creationValues = $creationFlash['values'];
$savedWork = \App\Services\AdminWorkDrafts::load('request', (int)$administrator['id']);
if ($creationValues === [] && ($_GET['resume'] ?? null) === '1' && $savedWork !== null) {
    $creationValues = $savedWork['values'] + ['work_draft_id' => $savedWork['id']];
}

$creationOptions = $customRequestController->creationOptions();
if ($creationValues === []) {
    $creationValues = ['customer_mode' => 'guest', 'preferred_contact_method' => 'PHONE'];
}
$selectedOrderCustomer = null;
if (($creationValues['customer_mode'] ?? '') === 'existing' && ctype_digit((string) ($creationValues['customer_id'] ?? ''))) {
    $selectedOrderCustomer = (new \App\Repositories\OrderRepository($databaseConnection))->findCustomer((int) $creationValues['customer_id']);
}
$pageTitle = 'Add custom request';
$activeNavigation = 'requests';
$breadcrumbs = [
    ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
    ['label' => 'Custom Requests', 'href' => BASE_URL . 'admin/requests/index.php'],
    ['label' => 'Add request'],
];
$contentView = VIEW_PATH . '/Pages/admin/requests/create.php';
require VIEW_PATH . '/Layouts/admin.php';
