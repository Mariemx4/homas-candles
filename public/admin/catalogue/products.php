<?php

declare(strict_types=1);



// STEP 1: Build catalogue dependencies and require an active administrator.
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-catalogue.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();

// Reuse one canonical URL for form actions, redirects, breadcrumbs, and links.
$selfUrl = BASE_URL . 'admin/catalogue/products.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // STEP 2A: POST means one edit form was submitted. The controller validates
    // CSRF/input and sends the named action to the product service.
    if (in_array($_POST['action'] ?? '', ['add_line','update_line','remove_line'], true)) {
        $recipeResult = $catalogueRecipeController->processAction($_POST);
        $result = $recipeResult + ['id' => $recipeResult['productId'], 'action' => 'recipe'];
    } else {
        $result = $adminProductController->processAction($_POST, $_FILES, (int) $administrator['id']);
    }
    if (in_array($_POST['action'] ?? '', ['save_category_rule', 'save_pricing_settings'], true)) {
        // A category's rule and its candle numbers belong to the category, not to a product:
        // return to that category's list, with the result shown in its rule window.
        $ruleCategory = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $ruleKey = 'category_rule_' . ($ruleCategory === false ? 'none' : $ruleCategory);
        if ($result['success']) {
            $authFlash->put($ruleKey, [], [], $_POST['action'] === 'save_pricing_settings'
                ? 'Candle numbers saved. Suggestions were recalculated; no selling price changed.'
                : 'Pricing rule saved.');
        } else {
            $authFlash->put($ruleKey . '_form', $result['errors'], $result['values']);
        }
        header('Location: ' . $selfUrl . ($ruleCategory === false ? '' : '?' . http_build_query(['category' => $ruleCategory])), true, 303);
        exit;
    }

    $id = $result['id'];

    if ($result['success']) {
        // Translate internal action names into messages meant for a person.
        $messages = [
            'add_quantity_price' => 'Quantity price added.', 'remove_quantity_price' => 'Quantity price removed.', 'recipe' => 'Production recipe saved.',
            'save_core' => 'Product details saved.',
            'change_category' => 'Category changed. The settings below now follow the new category.',
            'save_candle_weight' => 'Candle weight saved.',
            'apply_suggested_price' => 'Suggested price approved and applied.',
            'set_manual_price' => 'Selling price saved.',
            'apply_suggested_prices' => 'Approved suggested prices for the ticked candles.',
            'save_pricing_settings' => 'Pricing settings saved. Suggestions were recalculated; no selling price changed.',
            'save_identity' => 'Product name and description saved.',
            'create_draft' => 'Private draft saved. Add its photo, options and pricing next.',
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
        $location = $id === null ? $selfUrl : $selfUrl . '?' . http_build_query(['id' => $id]);
    } else {
        // Preserve validation errors and safe submitted values for redisplay.
        $authFlash->put('product_form_' . ($id ?? 'new'), $result['errors'], $result['values']);
        $location = $id === null ? $selfUrl . '?id=new' : $selfUrl . '?' . http_build_query(['id' => $id]);
    }
    // HTTP 303 explicitly tells the browser to follow the POST with a GET.
    if ($result['success'] && $result['action'] === 'create_draft') {
        $location .= '#product-media';
    }
    $returnOrder = is_string($_POST['return_order'] ?? null) ? filter_var($_POST['return_order'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
    if ($returnOrder !== false) {
        $parts = explode('#', $location, 2);
        $location = $parts[0] . (str_contains($parts[0], '?') ? '&' : '?') . 'return_order=' . $returnOrder . (isset($parts[1]) ? '#' . $parts[1] : '');
    }
    header('Location: ' . $location, true, 303);
    exit;
}
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
    $contentView = VIEW_PATH . '/Pages/admin/catalogue/product-edit.php';
} else {
    // STEP 2C: With no ID, load the searchable/paginated products list.
    $productList = $adminProductController->index($_GET);
    http_response_code((int) $productList['httpStatus']);
    // Flash messages for the pricing rule dialog, keyed by the category shown on this list.
    $ruleCategoryKey = 'category_rule_' . ($productList['filters']['category'] ?? 'none');
    $ruleNotice = $authFlash->consume($ruleCategoryKey);
    $ruleForm = $authFlash->consume($ruleCategoryKey . '_form');

    $pageTitle = 'Products';
    $activeNavigation = 'catalogue';
    $breadcrumbs = [
        ['label' => 'Administration', 'href' => BASE_URL . 'admin/index.php'],
        ['label' => 'Catalogue', 'href' => $selfUrl],
        ['label' => 'Products'],
    ];
    $contentView = VIEW_PATH . '/Pages/admin/catalogue/products.php';
}

// STEP 3: The chosen branch prepared `$contentView`; the layout renders it.
require VIEW_PATH . '/Layouts/admin.php';
