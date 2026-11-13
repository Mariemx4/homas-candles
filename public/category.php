<?php

declare(strict_types=1);

// Generic route for categories created in the administrator taxonomy screen.
// The six historical category URLs remain available through their wrappers.
$slug = is_string($_GET['slug'] ?? null) ? $_GET['slug'] : '';
if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
    http_response_code(404);
    $slug = '';
}

require_once __DIR__ . '/../app/Bootstrap/catalogue.php';

$categoryPage = [
    'slug' => $slug,
    'name' => $slug,
    'path' => 'category.php?slug=' . rawurlencode($slug),
    'eyebrow' => \App\Services\Locale::text('category.generic.eyebrow'),
    'title' => \App\Services\Locale::text('category.generic.title'),
    'description' => \App\Services\Locale::text('category.generic.description'),
    'empty_title' => \App\Services\Locale::text('category.generic.empty_title'),
];
$view = $catalogueController->category($categoryPage, $_GET);
$categoryPage = $view['categoryPage'];
extract($view, EXTR_SKIP);
require VIEW_PATH . '/Pages/category.php';
