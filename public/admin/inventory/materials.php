<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-inventory.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();

$selfUrl = BASE_URL . 'admin/inventory/materials.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = $materialController->processAction($_POST, (int) $administrator['id']);
    $id = $result['id'];

    if ($result['success']) {
        $messages = [
            'save_core' => 'Material saved.',
            'record_movement' => 'Stock movement recorded.',
            'activate' => 'Material activated.',
            'deactivate' => 'Material deactivated.',
        ];
        // A cost change can leave products unpriceable; the notice names any returned to draft.
        $authFlash->put('material_notice_' . $id, [], [], trim(($messages[$result['action']] ?? 'Material updated.') . ' ' . ($result['notice'] ?? '')));
        $location = $id === null ? $selfUrl : $selfUrl . '?' . http_build_query(['id' => $id]);
    } else {
        $authFlash->put('material_form_' . ($id ?? 'new'), $result['errors'], $result['values']);
        $location = $id === null ? $selfUrl . '?id=new' : $selfUrl . '?' . http_build_query(['id' => $id]);
    }
    $returnOrder = is_string($_POST['return_order'] ?? null) ? filter_var($_POST['return_order'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
    if ($returnOrder !== false) {
        $location .= (str_contains($location, '?') ? '&' : '?') . 'return_order=' . $returnOrder;
    }
    header('Location: ' . $location, true, 303);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}
header('Cache-Control: no-store, private');

$rawId = $_GET['id'] ?? null;
$isNew = $rawId === 'new';

if ($isNew || $rawId !== null) {
    $materialShow = $materialController->show($isNew ? null : $rawId);
    http_response_code((int) $materialShow['httpStatus']);
    $materialId = is_array($materialShow['detail']) && is_array($materialShow['detail']['material'] ?? null)
        ? (int) $materialShow['detail']['material']['id']
        : null;
    $materialNotice = $authFlash->consume('material_notice_' . ($materialId ?? 'new'));
    $materialForm = $authFlash->consume('material_form_' . ($materialId ?? 'new'));

    $pageTitle = $isNew ? 'New material' : (is_array($materialShow['detail']) ? (string) $materialShow['detail']['material']['name'] : 'Material');
    $activeNavigation = 'inventory';
    $breadcrumbs = [
        ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
        ['label' => 'Inventory', 'href' => $selfUrl],
        ['label' => $isNew ? 'New' : $pageTitle],
    ];
    $contentView = VIEW_PATH . '/Pages/admin/inventory/material-edit.php';
} else {
    $materialList = $materialController->index($_GET);
    http_response_code((int) $materialList['httpStatus']);

    $pageTitle = 'Materials';
    $activeNavigation = 'inventory';
    $breadcrumbs = [
        ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
        ['label' => 'Inventory'],
    ];
    $contentView = VIEW_PATH . '/Pages/admin/inventory/materials.php';
}
require VIEW_PATH . '/Layouts/admin.php';
