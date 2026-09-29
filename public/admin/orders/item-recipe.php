<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Administrator HTTP entry point for Item Recipe.
 *
 * DATA FLOW: The order detail page posts one item's custom production recipe
 * here. This route enforces administrator access, delegates to
 * OrderItemRecipeController, stores the outcome as a flash message, and
 * redirects back to the order (POST/Redirect/GET).
 */

// ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-orders.php';
// ROUTE STEP: Stop or redirect unless the session belongs to an active administrator; keep the returned account for the layout.
$administrator = $administratorAuthorization->requireActiveAdministrator();

// REQUEST GUARD: Only POST changes a recipe; the form lives on the order detail page.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$result = $orderItemRecipeController->process($_POST);
if ($result['orderId'] < 1) {
    header('Location: ' . BASE_URL . 'admin/orders/index.php', true, 303);
    exit;
}

// detail.php consumes this flash and shows it above the production panel.
$authFlash->put(
    'order_recipe_notice_' . $result['orderId'],
    $result['success'] ? [] : $result['errors'],
    [],
    $result['success'] ? 'Production recipe saved.' : null
);

// RESPONSE STEP: Redirect after processing so browser refresh repeats a GET rather than submitting the form again.
// URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
header('Location: ' . BASE_URL . 'admin/orders/detail.php?' . http_build_query(['id' => $result['orderId']]) . '#order-production-title', true, 303);
exit;
