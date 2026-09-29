<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Administrator HTTP entry point for Recipe.
 *
 * DATA FLOW: The web server invokes this file for a request. It loads bootstrap wiring, enforces administrator access, invokes a controller, and then redirects or selects a page view and layout.
 */

// ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-inventory.php';
// ROUTE STEP: Stop or redirect unless the session belongs to an active administrator; keep the returned account for the layout.
$administrator = $administratorAuthorization->requireActiveAdministrator();

// REQUEST BRANCH: POST represents a state-changing form submission, so validate security/input before calling its service action.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = $productRecipeController->processAction($_POST);
    if ($result['productId'] === null) {
        // RESPONSE STEP: Redirect after processing so browser refresh repeats a GET rather than submitting the form again.
        header('Location: ' . BASE_URL . 'admin/inventory/materials.php', true, 303);
        exit;
    }
    $productId = $result['productId'];
    if ($result['success']) {
        // Recipe changes can return dependent sets or diffusers to draft; say which.
        $authFlash->put('recipe_notice_' . $productId, [], [], trim('Recipe updated. ' . ($result['notice'] ?? '')));
    } else {
        $authFlash->put('recipe_form_' . $productId, $result['errors'], $result['values']);
    }
    // RESPONSE STEP: Redirect after processing so browser refresh repeats a GET rather than submitting the form again.
    header('Location: ' . BASE_URL . 'admin/inventory/recipe.php?' . http_build_query(['product_id' => $productId]), true, 303);
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

$recipeShow = $productRecipeController->show($_GET['product_id'] ?? null);
http_response_code((int) $recipeShow['httpStatus']);
$productId = is_array($recipeShow['detail']) ? (int) $recipeShow['detail']['product']['id'] : null;
$recipeNotice = $authFlash->consume('recipe_notice_' . ($productId ?? 'new'));
$recipeForm = $authFlash->consume('recipe_form_' . ($productId ?? 'new'));

$pageTitle = is_array($recipeShow['detail']) ? 'Recipe · ' . $recipeShow['detail']['product']['name'] : 'Product recipe';
$activeNavigation = 'inventory';
$breadcrumbs = [
    ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
    ['label' => 'Inventory', 'href' => BASE_URL . 'admin/inventory/materials.php'],
    ['label' => 'Recipe'],
];
// RENDER STEP: Select the inner page template; the layout will require this path inside its shared HTML shell.
$contentView = __DIR__ . '/../../../app/Views/Pages/admin-inventory/admin-inventory-recipe.php';

// RENDER STEP: Load the shared layout last, after all variables required by the selected page view have been prepared.
require __DIR__ . '/../../../app/Views/Layouts/admin.php';
