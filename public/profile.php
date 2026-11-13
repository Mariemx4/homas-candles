<?php

declare(strict_types=1);
require_once __DIR__ . '/../app/Bootstrap/session.php';
require_once __DIR__ . '/../app/config/database.php';
$customerId = $customerSession->requireCustomer();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET','POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}
header('Cache-Control: no-store, private');
$db = database();
$records = new \App\Repositories\AdminLifecycleRepository($db);
$profile = $records->find('customers', $customerId);
if (!$profile || $profile['account_status'] !== 'ACTIVE') {
    $customerSession->signOut();
    header('Location: ' . BASE_URL . 'login.php', true, 303);
    exit;
}
$flash = new \App\Auth\AuthFormFlash();
if ($method === 'POST') {
    $service = new \App\Services\AccountProfileService($records, new \App\Database\TransactionManager($db));
    $valid = $csrf->validate(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null);
    try {
        $result = !$valid ? ['success' => false,'errors' => ['form' => \App\Services\Locale::text('account.reload')]] :
            (($_POST['action'] ?? '') === 'deactivate_account'
                ? (($_POST['confirm_deactivate'] ?? '') === '1' ? $service->deactivateOwnAccount($customerId) : ['success' => false, 'errors' => ['form' => \App\Services\Locale::text('account.deactivate_confirm')]])
                : $service->save('customers', $customerId, $_POST, $customerId, true));
    } catch (Throwable $error) {
        error_log('Customer profile save failed (' . get_class($error) . ').');
        $result = ['success' => false,'errors' => ['form' => \App\Services\Locale::text('account.reload')]];
    }
    if ($result['success'] && ($_POST['action'] ?? '') === 'deactivate_account') {
        $customerSession->signOut();
        header('Location: ' . BASE_URL . 'login.php', true, 303);
        exit;
    }
    $values = $_POST;
    unset($values['csrf_token'],$values['password'],$values['password_hash']);
    $flash->put('customer_profile', $result['errors'], $result['success'] ? [] : $values, $result['success'] ? \App\Services\Locale::text('account.profile_saved') : null);
    header('Location: ' . BASE_URL . 'profile.php', true, 303);
    exit;
}
$profileFlash = $flash->consume('customer_profile');
// A failed security-only form has no contact fields. Merge retained entries
// over the current profile so it cannot accidentally blank the editor.
$values = array_replace($profile, $profileFlash['values']);
$addresses = $records->addresses($customerId);
$profileVersion = \App\Services\AccountProfileService::version($profile, $addresses);
$escape = static fn ($value) => htmlspecialchars(is_scalar($value) ? (string)$value : '', ENT_QUOTES, 'UTF-8');
$pageTitle = \App\Services\Locale::text('account.edit_profile') . " | HOM'AS";
require VIEW_PATH . '/Pages/customer-profile.php';
