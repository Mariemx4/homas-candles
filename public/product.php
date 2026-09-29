<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Customer-facing HTTP entry point for Product.
 *
 * DATA FLOW: The web server invokes this file for a request. It loads shared bootstrap code, handles request input through application classes, and renders or redirects the response.
 */

// ROUTE STEP: Load the autoloader first so the error boundary is always available,
// even when the feature bootstrap below fails halfway through.
require_once __DIR__ . '/../app/Bootstrap/autoload.php';

use App\Http\ErrorBoundary;

// WHAT: the HTTP boundary for this page — bootstrap wiring and data loading
//       both run inside one try/catch.
// WHY:  the bootstrap opens the database connection before
//       ProductController::show() and its own try/catch ever run, so an outage
//       used to escape as a bare 500. ErrorBoundary logs a protected incident
//       and answers 503 (database down) or 500 (anything else) with a
//       controlled page. `try` does not create a new scope, so the variables
//       the bootstrap defines are still available to the view below.
try {
    // ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.
    require_once __DIR__ . '/../app/Bootstrap/cart.php';

    $view = $productController->show((string) ($_GET['slug'] ?? ''));
    extract($view, EXTR_SKIP);
    $cartHasAnchor = $product !== null && $product['category_slug'] === 'accessories'
        ? $cartService->hasQualifyingProduct()
        : true;
} catch (Throwable $exception) {
    ErrorBoundary::respond($exception, 'product-page');
}

// WHAT: the controller caught a failure mid-query and the view will show its
//       in-page "temporarily unavailable" state.
// WHY:  the status must say so too, so browsers and search engines retry
//       instead of caching an error page as the product.
if ($productLoadError !== null) {
    http_response_code(503);
    header('Retry-After: ' . ErrorBoundary::RETRY_AFTER_SECONDS);
    header('Cache-Control: no-store, private');
}
// Request-only pieces show the documented social-contact options (app/config/contact.php).
$contactSettings = require __DIR__ . '/../app/config/contact.php';
$cartFlash = $_SESSION['cart_flash'] ?? null;
unset($_SESSION['cart_flash']);

require __DIR__ . '/../app/Views/Pages/product.php';
