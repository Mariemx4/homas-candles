<?php

declare(strict_types=1);
require_once __DIR__ . '/../app/Bootstrap/autoload.php';

use App\Services\{CustomerOrderService, Locale};
use App\Http\ErrorBoundary;

try {
    require_once __DIR__ . '/../app/Bootstrap/session.php';
    $customerId = $customerSession->requireCustomer();
    header('Cache-Control: no-store, private');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET','POST'], true)) {
        http_response_code(405);
        header('Allow: GET, POST');
        exit;
    }
    require_once __DIR__ . '/../app/Bootstrap/customer-orders.php';
    $view = $customerAccountController->order($customerId, $_GET['id'] ?? null);
    if ($view['account'] === null) {
        $customerSession->signOut();
        header('Location: ' . BASE_URL . 'login.php', true, 303);
        exit;
    }
    extract($view, EXTR_SKIP);
    $errors = [];
    $quote = null;
    $values = [];
    if ($order === null) {
        http_response_code(404);
    } else {
        $id = (int)$order['id'];
        $draftKey = 'customer_order_revision_' . $customerId . '_' . $id;
        $flash = new \App\Auth\AuthFormFlash();
        if ($method === 'POST') {
            $result = ['success' => false,'errors' => ['form' => Locale::text('account.reload')]];
            $values = $_POST;
            $draft = $_SESSION[$draftKey] ?? null;
            unset($_SESSION[$draftKey]);
            if ($csrf->validate(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
                if (($_POST['action'] ?? '') === 'save' && is_array($draft)
                    && is_string($_POST['review_token'] ?? null) && hash_equals($draft['token'], $_POST['review_token'])) {
                    $values = $draft['values'];
                    $result = $customerOrderService->update($customerId, $id, $values, $draft['version'], $draft['signature']);
                    if ($result['success']) {
                        $flash->put('customer_order_' . $id, [], [], Locale::text('account.order_saved'));
                        header('Location: ' . BASE_URL . 'account-order.php?id=' . $id, true, 303);
                        exit;
                    }
                } elseif (($_POST['action'] ?? '') === 'preview') {
                    $version = is_string($_POST['version'] ?? null) ? $_POST['version'] : '';
                    $result = $customerOrderService->preview($customerId, $id, $_POST, $version);
                    if ($result['success']) {
                        $_SESSION[$draftKey] = ['values' => $_POST,'version' => $version,'signature' => $result['signature'],'quote' => $result['quote'],'token' => bin2hex(random_bytes(24))];
                    }
                }
            }
            $flash->put($draftKey, $result['errors'], $values);
            header('Location: ' . BASE_URL . 'account-order-edit.php?id=' . $id, true, 303);
            exit;
        }
        $state = $flash->consume($draftKey);
        $errors = $state['errors'];
        $values = $state['values'] ?: [
            'delivery_method' => $order['delivery_method'],'required_date' => $order['required_date'],'customer_notes' => $order['customer_notes'],
            'street_address' => $order['street_address_snapshot'],'delegation' => $order['delegation_snapshot'],
            'governorate' => $order['governorate_snapshot'],'postal_code' => $order['postal_code_snapshot'],
            'quantities' => array_column($items, 'quantity', 'id'),
        ];
        $version = CustomerOrderService::version($order, $items);
        $canEdit = CustomerOrderService::editable($order);
        $draft = $_SESSION[$draftKey] ?? null;
        if (is_array($draft) && hash_equals($version, $draft['version']) && $canEdit) {
            $quote = $draft['quote'];
            $values = $draft['values'];
        } elseif ($draft !== null) {
            unset($_SESSION[$draftKey]);
            $errors['form'] = Locale::text('account.order_changed');
        }
    }
} catch (Throwable $e) {
    ErrorBoundary::respond($e, 'customer-order-edit');
}
$pageTitle = Locale::text('account.edit_order') . " | HOM'AS";
require VIEW_PATH . '/Pages/customer-order-edit.php';
