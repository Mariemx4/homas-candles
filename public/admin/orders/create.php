<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-orders.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
header('Cache-Control: no-store, private');

// POST handles the mutation first and always redirects. This PRG pattern means
// that refreshing the resulting page cannot create the same order twice.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (($_POST['action'] ?? '') === 'discard_draft') {
        $valid = $csrf->validate(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null);
        $csrf->rotate();
        if ($valid) {
            \App\Services\AdminWorkDrafts::forget('order', (int)$administrator['id']);
        } else {
            $authFlash->put('manual_order_create', ['form' => 'The form expired. Review the saved draft and try again.'], []);
        }
        header('Location: ' . BASE_URL . 'admin/orders/create.php', true, 303);
        exit;
    }

    if (in_array($_POST['action'] ?? '', ['review_pricing','remove_promo'], true)) {
        if ($_POST['action'] === 'remove_promo') {
            $_POST['promo_code'] = '';
        }
        $review = $manualOrderController->review($_POST);
        if (is_string($_POST['work_draft_id'] ?? null)) {
            $review['values']['work_draft_id'] = $_POST['work_draft_id'];
        }
        $authFlash->put('manual_order_create', $review['errors'], $review['values']);
        header('Location: ' . BASE_URL . 'admin/orders/create.php', true, 303);
        exit;
    }
    $result = $manualOrderController->process($_POST, (int) $administrator['id'], ($_POST['action'] ?? '') === 'save_draft');
    if (!empty($result['draftSaved'])) {
        header('Location: ' . BASE_URL . 'admin/orders/create.php?resume=1', true, 303);
        exit;
    }
    if (is_string($_POST['work_draft_id'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $_POST['work_draft_id'])) {
        $result['values']['work_draft_id'] = $_POST['work_draft_id'];
    }

    if ($result['success']) {
        if (is_string($_POST['work_draft_id'] ?? null)) {
            \App\Services\AdminWorkDrafts::forget('order', (int)$administrator['id'], $_POST['work_draft_id']);
        }
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
$savedWork = \App\Services\AdminWorkDrafts::load('order', (int)$administrator['id']);
if ($manualOrderValues === [] && ($_GET['resume'] ?? null) === '1' && $savedWork !== null) {
    $manualOrderValues = $savedWork['values'] + ['work_draft_id' => $savedWork['id']];
}

if ($manualOrderValues === []) {
    // A complete default shape keeps presentation logic simple and prevents
    // undefined-index notices on the first visit.
    $manualOrderValues = [
        'customer_mode' => 'guest', 'customer_id' => '', 'create_customer' => '', 'customer_second_phone' => '', 'copy_account_address' => '1',
        'customer_name' => '', 'customer_email' => '', 'customer_phone' => '',
        'delivery_method' => 'PICKUP', 'required_date' => '', 'delivery_fee' => '0.000',
        'street_address' => '', 'delegation' => '', 'governorate' => '', 'postal_code' => '',
        'customer_notes' => '', 'administrator_notes' => '',
        'items' => [['product_id' => '', 'color_id' => '', 'scent_id' => '', 'product_name' => '', 'quantity' => '1',
            'unit_price' => '', 'customization_notes' => '', 'recipe' => []]],
    ];
    // Customer-directory links preselect and copy the account's current details.
    // Never replace a failed POST's flash values with query-string defaults.
    if (isset($_GET['customer_id'])) {
        $customerId = is_string($_GET['customer_id'])
            ? filter_var($_GET['customer_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        try {
            $linkedCustomer = $customerId === false ? null : $orderRepository->findCustomer($customerId);
            if ($linkedCustomer === null) {
                http_response_code(404);
                $manualOrderFlash['errors']['customer_id'] = 'Customer account not found. Search and select an available account.';
            } else {
                $manualOrderValues['customer_id'] = (string) $linkedCustomer['id'];
                $manualOrderValues['customer_mode'] = 'existing';
                foreach (['customer_name', 'customer_email', 'customer_phone', 'street_address', 'delegation', 'governorate', 'postal_code'] as $key) {
                    $manualOrderValues[$key] = (string) ($linkedCustomer[$key] ?? '');
                }
            }
        } catch (Throwable) {
            http_response_code(503);
            $manualOrderFlash['errors']['customer_id'] = 'Customer accounts are temporarily unavailable. Please try again.';
        }
    }
}
// Active catalogue products (with their active colors/scents) and active
// materials offered by the item and recipe selectors.
$manualOrderOptions = $manualOrderController->formOptions();
$manualOrderPricing = $manualOrderService->preview($manualOrderValues);
$deliveryFeeDefault = \App\Services\Money::formatMillis((require APP_PATH . '/config/checkout.php')['delivery_fee_millis']);

$pageTitle = 'Create manual order';
$selectedOrderCustomer = null;
try {
    if (!empty($manualOrderValues['customer_id']) && ctype_digit($manualOrderValues['customer_id'])) {
        $selectedOrderCustomer = $orderRepository->findCustomer((int) $manualOrderValues['customer_id']);
    }
} catch (Throwable) {
    http_response_code(503);
    $manualOrderFlash['errors']['customer_id'] = 'Customer accounts are temporarily unavailable. Please try again.';
}
$activeNavigation = 'orders';
$breadcrumbs = [
    ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
    ['label' => 'Orders', 'href' => BASE_URL . 'admin/orders/index.php'],
    ['label' => 'Create manual order'],
];
$contentView = VIEW_PATH . '/Pages/admin/orders/create.php';
require VIEW_PATH . '/Layouts/admin.php';
