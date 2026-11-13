<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-inventory.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $result = $productRecipeController->processAction($_POST);
    if ($result['productId'] === null) {
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
    header('Location: ' . BASE_URL . 'admin/inventory/recipe.php?' . http_build_query(['product_id' => $productId]), true, 303);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed.');
}
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
$contentView = VIEW_PATH . '/Pages/admin/inventory/recipe.php';
require VIEW_PATH . '/Layouts/admin.php';
