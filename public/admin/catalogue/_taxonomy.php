<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Administrator HTTP entry point for Taxonomy.
 *
 * DATA FLOW: The web server invokes this file for a request. It loads bootstrap wiring, enforces administrator access, invokes a controller, and then redirects or selects a page view and layout.
 */

use App\Services\TaxonomyService;

if (!isset($administratorAuthorization, $taxonomyKind) || !in_array($taxonomyKind, TaxonomyService::KINDS, true)) {
    http_response_code(404);
    exit('Not found.');
}

$selfUrl = BASE_URL . 'admin/catalogue/' . str_replace('_', '-', $taxonomyKind) . '.php';

// REQUEST BRANCH: POST represents a state-changing form submission, so validate security/input before calling its service action.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $formAction = isset($_POST['form_action']) && is_string($_POST['form_action']) ? $_POST['form_action'] : '';

    if ($formAction === 'toggle') {
        $result = $taxonomyController->processToggle($taxonomyKind, $_POST);
    } else {
        $result = $taxonomyController->processSave($taxonomyKind, $_POST);
    }

    $id = $result['id'] ?? null;
    if ($result['success']) {
        // Retiring a color or scent can return products to draft; the notice names them.
        $authFlash->put(
            'taxonomy_' . $taxonomyKind . '_notice',
            [],
            [],
            trim(ucfirst(strtolower(TaxonomyService::label($taxonomyKind))) . ' saved. ' . ($result['notice'] ?? ''))
        );
        // URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
        $location = $formAction === 'toggle' ? $selfUrl : $selfUrl . '?' . http_build_query(['id' => $id]);
    } else {
        $flashKey = $formAction === 'toggle' ? 'taxonomy_' . $taxonomyKind . '_notice' : 'taxonomy_' . $taxonomyKind . '_form_' . ($id ?? 'new');
        $authFlash->put($flashKey, $result['errors'], $result['values'] ?? []);
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
$taxonomyNotice = $authFlash->consume('taxonomy_' . $taxonomyKind . '_notice');

if ($isNew || $rawId !== null) {
    $taxonomyShow = $taxonomyController->show($taxonomyKind, $isNew ? null : $rawId);
    http_response_code((int) $taxonomyShow['httpStatus']);
    $formFlashKey = 'taxonomy_' . $taxonomyKind . '_form_' . ($isNew ? 'new' : $rawId);
    $taxonomyForm = $authFlash->consume($formFlashKey);

    $pageTitle = ($isNew ? 'New ' : 'Edit ') . TaxonomyService::label($taxonomyKind);
    $activeNavigation = 'catalogue';
    $breadcrumbs = [
        ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
        ['label' => 'Catalogue', 'href' => BASE_URL . 'admin/catalogue/products.php'],
        ['label' => TaxonomyService::label($taxonomyKind, true), 'href' => $selfUrl],
        ['label' => $isNew ? 'New' : 'Edit'],
    ];
    // RENDER STEP: Select the inner page template; the layout will require this path inside its shared HTML shell.
    $contentView = __DIR__ . '/../../../app/Views/Pages/admin-catalogue/admin-catalogue-taxonomy-edit.php';
} else {
    $taxonomyList = $taxonomyController->index($taxonomyKind, $_GET);
    http_response_code((int) $taxonomyList['httpStatus']);

    $pageTitle = TaxonomyService::label($taxonomyKind, true);
    $activeNavigation = 'catalogue';
    $breadcrumbs = [
        ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
        ['label' => 'Catalogue', 'href' => BASE_URL . 'admin/catalogue/products.php'],
        ['label' => TaxonomyService::label($taxonomyKind, true)],
    ];
    // RENDER STEP: Select the inner page template; the layout will require this path inside its shared HTML shell.
    $contentView = __DIR__ . '/../../../app/Views/Pages/admin-catalogue/admin-catalogue-taxonomy-list.php';
}

// RENDER STEP: Load the shared layout last, after all variables required by the selected page view have been prepared.
require __DIR__ . '/../../../app/Views/Layouts/admin.php';
