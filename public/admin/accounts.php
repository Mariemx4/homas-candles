<?php

declare(strict_types=1);
require_once __DIR__ . '/../../app/Bootstrap/admin/administrator-auth.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
header('Cache-Control: no-store, private');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET','POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}
$records = new \App\Repositories\AdminLifecycleRepository($databaseConnection);
$input = $method === 'POST' ? $_POST : $_GET;
$kind = $input['kind'] ?? 'administrators';
if (!is_string($kind) || !in_array($kind, ['customers','administrators'], true)) {
    http_response_code(400);
    exit('Select a valid account type.');
}
$raw = $input['id'] ?? null;
$id = $raw === 'new' || $raw === null ? null : (is_string($raw) ? filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false);
if ($id === false) {
    http_response_code(400);
    exit('Select a valid account.');
}
$accountReturn = (new \App\Services\AdminCustomerService(new \App\Repositories\AdminCustomerRepository($databaseConnection)))->normalizedReturnQuery(is_string($input['return'] ?? null) ? $input['return'] : '');
$customerReturnUrl = $kind === 'customers' && $id !== null ? BASE_URL . 'admin/customers/detail.php?' . http_build_query(['id' => $id,'return' => $accountReturn]) : null;
if ($method === 'POST') {
    $valid = $csrf->validate(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null);
    $csrf->rotate();
    try {
        $result = $valid ? (new \App\Services\AccountProfileService($records, new \App\Database\TransactionManager($databaseConnection)))->save($kind, $id, $_POST, (int)$administrator['id']) : ['success' => false,'errors' => ['form' => 'Reload the form and try again.']];
    } catch (Throwable $error) {
        error_log('Account profile save failed (' . get_class($error) . ').');
        $result = ['success' => false,'errors' => ['form' => 'The changes could not be saved. Your entries are retained; please try again.']];
    }
    $values = $_POST;
    unset($values['password'],$values['password_hash'],$values['csrf_token']);
    $authFlash->put('account_profile', $result['errors'], $result['success'] ? [] : $values, $result['success'] ? 'Current profile saved; historical snapshots retained.' : null);
    if ($result['success'] && $customerReturnUrl !== null) {
        $authFlash->consume('account_profile');
        $authFlash->put('customer_profile_notice_' . $id, [], [], 'Customer details saved.');
        header('Location: ' . $customerReturnUrl, true, 303);
        exit;
    }
    header('Location: ' . BASE_URL . 'admin/accounts.php?' . http_build_query(['kind' => $kind,'id' => $result['id'] ?? ($id ?? 'new'),'return' => $accountReturn]), true, 303);
    exit;
}
$accountFlash = $authFlash->consume('account_profile');
$profile = $id !== null ? $records->find($kind, $id) : null;
if ($id !== null && !$profile) {
    http_response_code(404);
    exit('Account not found.');
}
$accountList = $kind === 'administrators' && $raw === null ? $records->administrators() : null;
$addresses = $kind === 'customers' && $id !== null ? $records->addresses($id) : [];
$currentProfileVersion = is_array($profile) ? \App\Services\AccountProfileService::version($profile, $addresses) : '';
$historyTotal = $records->accountHistoryCount($kind, $id);
$historyPages = max(1, (int)ceil($historyTotal / 20));
$historyPage = filter_var($_GET['history_page'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$historyPage = min($historyPage, $historyPages);
$accountHistory = $records->accountHistory($kind, $id, 20, ($historyPage - 1) * 20);
$pageTitle = 'Account management';
$activeNavigation = $kind === 'customers' ? 'customers' : 'accounts';
$breadcrumbs = [];
$contentView = VIEW_PATH . '/Pages/admin/accounts/index.php';
require VIEW_PATH . '/Layouts/admin.php';
