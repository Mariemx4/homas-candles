<?php

declare(strict_types=1);

// All active categories use this route, including admin-created collections.
$slug = is_string($_GET['slug'] ?? null) ? $_GET['slug'] : '';
if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
    http_response_code(404);
    $slug = '';
}

require_once __DIR__ . '/../app/Bootstrap/catalogue.php';

$view = $catalogueController->category($slug, $_GET);
extract($view, EXTR_SKIP);
require VIEW_PATH . '/Pages/category.php';
