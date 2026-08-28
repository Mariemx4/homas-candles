<?php
// WHAT: the Candles category page — just this page's copy/slug, rendered
//       by the shared app/Views/Pages/category.php template.
// WHY:  every category page follows this exact shape; see category-page.php
//       for what each key does and how $products/filters get rendered.
$categoryPage = [
  'slug' => 'candles',
  'name' => 'Candles',
  'path' => 'categories/candles.php',
  'eyebrow' => 'Sculptural light',
  'title' => 'Candles shaped by hand, made to soften a room.',
  'description' => 'Discover floral forms and expressive silhouettes created with patience, care, and the unmistakable Hom\'As touch.',
  'empty_title' => 'The candle collection is being prepared.',
];

require_once __DIR__ . '/../../app/Bootstrap/catalogue.php';

$view = $catalogueController->category($categoryPage, $_GET);
extract($view, EXTR_SKIP);

require __DIR__ . '/../../app/Views/Pages/category.php';
