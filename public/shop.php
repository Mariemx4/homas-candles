<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Customer-facing HTTP entry point for Shop.
 *
 * DATA FLOW: The web server invokes this file for a request. It loads shared bootstrap code, handles request input through application classes, and renders or redirects the response.
 */

// ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.
require_once __DIR__ . '/../app/Bootstrap/catalogue.php';

$view = $catalogueController->shop($_GET);
extract($view, EXTR_SKIP);

require __DIR__ . '/../app/Views/Pages/shop.php';
