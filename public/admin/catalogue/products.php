<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Administrator HTTP entry point for Products.
 *
 * DATA FLOW: The web server invokes this file for a request. It loads bootstrap wiring, enforces administrator access, invokes a controller, and then redirects or selects a page view and layout.
 */

// STEP 1: Build catalogue dependencies and require an active administrator.
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-catalogue.php';
// ROUTE STEP: Stop or redirect unless the session belongs to an active administrator; keep the returned account for the layout.
$administrator = $administratorAuthorization->requireActiveAdministrator();

// Reuse one canonical URL for form actions, redirects, breadcrumbs, and links.
$selfUrl = BASE_URL . 'admin/catalogue/products.php';

// REQUEST BRANCH: POST represents a state-changing form submission, so validate security/input before calling its service action.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // STEP 2A: POST means one edit form was submitted. The controller validates
    // CSRF/input and sends the named action to the product service.
    if (in_array($_POST['action'] ?? '', ['add_line','update_line','remove_line'], true)) {
        $recipeResult = $catalogueRecipeController->processAction($_POST);
        $result = $recipeResult + ['id' => $recipeResult['productId'], 'action' => 'recipe'];
    } else {
        $result = $adminProductController->processAction($_POST, $_FILES, (int) $administrator['id']);
    }
    $id = $result['id'];

    if ($result['success']) {
        // Translate internal action names into messages meant for a person.
        $messages = [
            'add_quantity_price' => 'Quantity price added.', 'remove_quantity_price' => 'Quantity price removed.', 'recipe' => 'Production recipe saved.',
            'save_core' => 'Product details saved.',
            'save_translation' => 'Translation saved.',
            'generate_translations' => 'Translation generation finished.',
            'approve_translation' => 'Generated translation approved.',
            'reject_translation' => 'Generated translation rejected.',
            'add_color' => 'Color assigned.',
            'remove_color' => 'Color removed.',
            'upload_color_image' => 'Color photo uploaded.',
            'upload_main_image' => 'Main photo uploaded.',
            'add_scent' => 'Scent assigned.',
            'remove_scent' => 'Scent removed.',
            'add_component' => 'Component added.',
            'remove_component' => 'Component removed.',
            'activate' => 'Product activated.',
            'deactivate' => 'Product deactivated.',
        ];
        // Flash data survives exactly one redirect. This implements the
        // POST/Redirect/GET pattern and prevents refresh from resubmitting.
        // Append any readiness notice so the admin learns which products went
        // back to draft as a consequence of this change (ProductReadinessService).
        $authFlash->put(
            'product_notice_' . $id,
            [],
            [],
            trim(($messages[$result['action']] ?? 'Product updated.') . ' ' . ($result['notice'] ?? ''))
        );
        // URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
        $location = $id === null ? $selfUrl : $selfUrl . '?' . http_build_query(['id' => $id]);
    } else {
        // Preserve validation errors and safe submitted values for redisplay.
        $authFlash->put('product_form_' . ($id ?? 'new'), $result['errors'], $result['values']);
        // URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
        $location = $id === null ? $selfUrl . '?id=new' : $selfUrl . '?' . http_build_query(['id' => $id]);
    }
    // HTTP 303 explicitly tells the browser to follow the POST with a GET.
    header('Location: ' . $location, true, 303);
    exit;
}

// REQUEST GUARD: Reject HTTP methods this route does not implement instead of handling them accidentally.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    // Only GET and POST belong to this endpoint; advertise that contract.
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}

// Product screens contain private administrator data and should not be cached.
header('Cache-Control: no-store, private');

$rawId = $_GET['id'] ?? null;
$isNew = $rawId === 'new';

if ($isNew || $rawId !== null) {
    // STEP 2B: `?id=new` and `?id=123` select the product editor branch.
    $productShow = $adminProductController->show($isNew ? null : $rawId);
    http_response_code((int) $productShow['httpStatus']);
    $productId = is_array($productShow['detail']) && is_array($productShow['detail']['product'] ?? null)
        ? (int) $productShow['detail']['product']['id']
        : null;
    $catalogueRecipeDetail = $productId === null ? null : $catalogueRecipeController->show((string) $productId)['detail'];
    // Flash keys include the product ID so messages from different products do
    // not become attached to the wrong edit screen.
    $productNotice = $authFlash->consume('product_notice_' . ($productId ?? 'new'));
    $productForm = $authFlash->consume('product_form_' . ($productId ?? 'new'));

    $pageTitle = $isNew
        ? 'New product'
        : (is_array($productShow['detail']) ? (string) ($productShow['detail']['translations']['en']['name'] ?: $productShow['detail']['product']['slug'] ?: ('Product #' . $productId)) : 'Product');
    $activeNavigation = 'catalogue';
    $breadcrumbs = [
        ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
        ['label' => 'Catalogue', 'href' => $selfUrl],
        ['label' => 'Products', 'href' => $selfUrl],
        ['label' => $isNew ? 'New' : $pageTitle],
    ];
    // RENDER STEP: Select the inner page template; the layout will require this path inside its shared HTML shell.
    $contentView = __DIR__ . '/../../../app/Views/Pages/admin-catalogue/admin-catalogue-product-edit.php';
} else {
    // STEP 2C: With no ID, load the searchable/paginated products list.
    $productList = $adminProductController->index($_GET);
    http_response_code((int) $productList['httpStatus']);

    $pageTitle = 'Products';
    $activeNavigation = 'catalogue';
    $breadcrumbs = [
        ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
        ['label' => 'Catalogue', 'href' => $selfUrl],
        ['label' => 'Products'],
    ];
    // RENDER STEP: Select the inner page template; the layout will require this path inside its shared HTML shell.
    $contentView = __DIR__ . '/../../../app/Views/Pages/admin-catalogue/admin-catalogue-products.php';
}

// STEP 3: The chosen branch prepared `$contentView`; the layout renders it.
require __DIR__ . '/../../../app/Views/Layouts/admin.php';
