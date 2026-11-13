<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-orders.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $directSelection = ($_POST['inline_status'] ?? null) === '1';
    $result = $orderStatusController->process($_POST, (int) $administrator['id'], $directSelection);
    if ($result['orderId'] === null) {
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
        $location = BASE_URL . 'admin/orders/status.php?' . http_build_query([
            'id' => $orderId,
            'to' => $result['newStatus'],
            'return' => $result['returnQuery'],
        ]);
    }
    header('Location: ' . $location, true, 303);
    exit;
}
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
$contentView = VIEW_PATH . '/Pages/admin/orders/status.php';
require VIEW_PATH . '/Layouts/admin.php';
