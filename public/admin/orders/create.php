<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-orders.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();

// POST handles the mutation first and always redirects. This PRG pattern means
// that refreshing the resulting page cannot create the same order twice.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = $manualOrderController->process($_POST, (int) $administrator['id']);
    if ($result['success']) {
        $authFlash->put(
            'order_transition_notice_' . (int) $result['orderId'],
            [],
            [],
            'Manual order ' . (string) $result['orderNumber'] . ' created.'
        );
        header('Location: ' . BASE_URL . 'admin/orders/detail.php?' . http_build_query(['id' => $result['orderId']]), true, 303);
        exit;
    }
    $authFlash->put('manual_order_create', $result['errors'], $result['values']);
    header('Location: ' . BASE_URL . 'admin/orders/create.php', true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}

$manualOrderFlash = $authFlash->consume('manual_order_create');
$manualOrderValues = $manualOrderFlash['values'];
if ($manualOrderValues === []) {
    // A complete default shape keeps presentation logic simple and prevents
    // undefined-index notices on the first visit.
    $manualOrderValues = [
        'customer_name' => '', 'customer_email' => '', 'customer_phone' => '',
        'delivery_method' => 'PICKUP', 'required_date' => '', 'delivery_fee' => '0.000',
        'street_address' => '', 'delegation' => '', 'governorate' => '', 'postal_code' => '',
        'customer_notes' => '', 'administrator_notes' => '',
        'items' => [['product_id' => '', 'color_id' => '', 'scent_id' => '', 'product_name' => '', 'quantity' => '1',
            'unit_price' => '', 'customization_notes' => '', 'recipe' => []]],
    ];
}
// Active catalogue products (with their active colors/scents) and active
// materials offered by the item and recipe selectors.
$manualOrderOptions = $manualOrderController->formOptions();

$pageTitle = 'Create manual order';
$selectedOrderCustomer = !empty($manualOrderValues['customer_id']) && ctype_digit($manualOrderValues['customer_id']) ? $orderRepository->findCustomer((int) $manualOrderValues['customer_id']) : null;
$activeNavigation = 'orders';
$breadcrumbs = [
    ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
    ['label' => 'Orders', 'href' => BASE_URL . 'admin/orders/index.php'],
    ['label' => 'Create manual order'],
];
$contentView = __DIR__ . '/../../../app/Views/Pages/admin-order/admin-order-create.php';
require __DIR__ . '/../../../app/Views/Layouts/admin.php';
