<?php

declare(strict_types=1);
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
    require_once __DIR__ . '/../app/Bootstrap/cart.php';

    $view = $productController->show((string) ($_GET['slug'] ?? ''));
    extract($view, EXTR_SKIP);
    $cartHasAnchor = $product !== null && $product['category_slug'] === 'accessories'
        ? $cartService->hasQualifyingProduct()
        : true;
} catch (Throwable $exception) {
    ErrorBoundary::respond($exception, 'product-page');
}

// A missing or unavailable product is a 404; a failed backend lookup is a 503.
// The page keeps its friendly state in both cases, with an accurate HTTP status.
http_response_code($httpStatus);
if ($productLoadError !== null) {
    header('Retry-After: ' . ErrorBoundary::RETRY_AFTER_SECONDS);
    header('Cache-Control: no-store, private');
}
// Request-only pieces show the documented social-contact options (app/config/contact.php).
$contactSettings = require __DIR__ . '/../app/config/contact.php';
$cartFlash = $_SESSION['cart_flash'] ?? null;
unset($_SESSION['cart_flash']);

require VIEW_PATH . '/Pages/product.php';
