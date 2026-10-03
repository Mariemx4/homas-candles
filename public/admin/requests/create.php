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
    $result = $customRequestController->processCreation($_POST, (int) $administrator['id']);
    if ($result['success']) {
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
$creationOptions = $customRequestController->creationOptions();
if ($creationValues === []) {
    $creationValues = ['customer_mode' => 'guest', 'preferred_contact_method' => 'PHONE', 'assigned_administrator_id' => (string) $administrator['id']];
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
$contentView = __DIR__ . '/../../../app/Views/Pages/admin-custom-request/admin-custom-request-create.php';
require __DIR__ . '/../../../app/Views/Layouts/admin.php';
