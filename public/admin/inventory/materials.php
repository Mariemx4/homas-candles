<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Administrator HTTP entry point for Materials.
 *
 * DATA FLOW: The web server invokes this file for a request. It loads bootstrap wiring, enforces administrator access, invokes a controller, and then redirects or selects a page view and layout.
 */

// ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-inventory.php';
// ROUTE STEP: Stop or redirect unless the session belongs to an active administrator; keep the returned account for the layout.
$administrator = $administratorAuthorization->requireActiveAdministrator();

$selfUrl = BASE_URL . 'admin/inventory/materials.php';

// REQUEST BRANCH: POST represents a state-changing form submission, so validate security/input before calling its service action.
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
        // URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
        $location = $id === null ? $selfUrl : $selfUrl . '?' . http_build_query(['id' => $id]);
    } else {
        $authFlash->put('material_form_' . ($id ?? 'new'), $result['errors'], $result['values']);
        // URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
        $location = $id === null ? $selfUrl . '?id=new' : $selfUrl . '?' . http_build_query(['id' => $id]);
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

// RESPONSE POLICY: Tell browsers/proxies how this private or changing response may be cached.
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
    // RENDER STEP: Select the inner page template; the layout will require this path inside its shared HTML shell.
    $contentView = __DIR__ . '/../../../app/Views/Pages/admin-inventory/admin-inventory-material-edit.php';
} else {
    $materialList = $materialController->index($_GET);
    http_response_code((int) $materialList['httpStatus']);

    $pageTitle = 'Materials';
    $activeNavigation = 'inventory';
    $breadcrumbs = [
        ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
        ['label' => 'Inventory'],
    ];
    // RENDER STEP: Select the inner page template; the layout will require this path inside its shared HTML shell.
    $contentView = __DIR__ . '/../../../app/Views/Pages/admin-inventory/admin-inventory-materials.php';
}

// RENDER STEP: Load the shared layout last, after all variables required by the selected page view have been prepared.
require __DIR__ . '/../../../app/Views/Layouts/admin.php';
