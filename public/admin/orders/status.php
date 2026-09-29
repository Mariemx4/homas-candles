<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Administrator HTTP entry point for Status.
 *
 * DATA FLOW: The web server invokes this file for a request. It loads bootstrap wiring, enforces administrator access, invokes a controller, and then redirects or selects a page view and layout.
 */

// ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-orders.php';
// ROUTE STEP: Stop or redirect unless the session belongs to an active administrator; keep the returned account for the layout.
$administrator = $administratorAuthorization->requireActiveAdministrator();

// REQUEST BRANCH: POST represents a state-changing form submission, so validate security/input before calling its service action.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $directSelection = ($_POST['inline_status'] ?? null) === '1';
    $result = $orderStatusController->process($_POST, (int) $administrator['id'], $directSelection);
    if ($result['orderId'] === null) {
        // RESPONSE STEP: Redirect after processing so browser refresh repeats a GET rather than submitting the form again.
        header('Location: ' . BASE_URL . 'admin/orders/index.php', true, 303);
        exit;
    }

    $orderId = (int) $result['orderId'];
    if ($directSelection && ($_POST['return_to'] ?? null) === 'list') {
        $authFlash->put('order_status_list_notice', $result['errors'], [], $result['success']
            ? 'Order ' . (string) $result['orderNumber'] . ' updated to ' . ucwords(strtolower(str_replace('_', ' ', $result['newStatus']))) . '.' : null);
        $location = BASE_URL . 'admin/orders/index.php' . ($result['returnQuery'] !== '' ? '?' . $result['returnQuery'] : '');
        header('Location: ' . $location, true, 303);
        exit;
    }
    if ($result['success']) {
        $authFlash->put(
            'order_transition_notice_' . $orderId,
            [],
            [],
            'Order ' . (string) $result['orderNumber'] . ' moved to '
                . ucwords(strtolower(str_replace('_', ' ', (string) $result['newStatus']))) . '.'
        );
        // URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
        $location = BASE_URL . 'admin/orders/detail.php?' . http_build_query([
            'id' => $orderId,
            'return' => $result['returnQuery'],
        ]);
    } elseif ($directSelection) {
        $authFlash->put('order_transition_notice_' . $orderId, $result['errors'], []);
        $location = BASE_URL . 'admin/orders/detail.php?' . http_build_query([
            'id' => $orderId,
            'return' => $result['returnQuery'],
        ]);
    } else {
        $authFlash->put(
            'order_transition_form_' . $orderId,
            $result['errors'],
            $result['values']
        );
        // URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
        $location = BASE_URL . 'admin/orders/status.php?' . http_build_query([
            'id' => $orderId,
            'to' => $result['newStatus'],
            'return' => $result['returnQuery'],
        ]);
    }
    // RESPONSE STEP: Redirect after processing so browser refresh repeats a GET rather than submitting the form again.
    header('Location: ' . $location, true, 303);
    exit;
}

// REQUEST GUARD: Reject HTTP methods this route does not implement instead of handling them accidentally.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}

$orderDetail = $orderController->show($_GET);
$transitionConfirmation = is_array($orderDetail['order'])
    ? $orderStatusController->confirmation($orderDetail['order'], $_GET['to'] ?? null)
    : null;
if (is_array($transitionConfirmation) && !$transitionConfirmation['validProposal']) {
    $orderDetail['httpStatus'] = 400;
}
http_response_code((int) $orderDetail['httpStatus']);
$orderId = is_array($orderDetail['order']) ? (int) $orderDetail['order']['id'] : 0;
$transitionForm = $orderId > 0
    ? $authFlash->consume('order_transition_form_' . $orderId)
    : ['errors' => [], 'values' => [], 'success' => null];

$orderNumber = is_array($orderDetail['order']) ? (string) $orderDetail['order']['order_number'] : 'Status transition';
$pageTitle = 'Update ' . $orderNumber;
$activeNavigation = 'orders';
// URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
$backUrl = BASE_URL . 'admin/orders/detail.php?' . http_build_query([
    'id' => $orderId,
    'return' => $orderDetail['backQuery'],
]);
$breadcrumbs = [
    ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
    ['label' => 'Orders', 'href' => BASE_URL . 'admin/orders/index.php'],
    ['label' => $orderNumber, 'href' => $backUrl],
    ['label' => 'Confirm status'],
];
// RENDER STEP: Select the inner page template; the layout will require this path inside its shared HTML shell.
$contentView = __DIR__ . '/../../../app/Views/Pages/admin-order/admin-order-status.php';

// RENDER STEP: Load the shared layout last, after all variables required by the selected page view have been prepared.
require __DIR__ . '/../../../app/Views/Layouts/admin.php';
