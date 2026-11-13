<?php


// WHAT: the Accessories category page — see candles.php for the shared pattern.
$categoryPage = [
  'slug' => 'accessories',
  'name' => 'Accessories',
  'path' => 'categories/accessories.php',
  'eyebrow' => 'The finishing details',
  'title' => 'Thoughtful accents for your candle ritual.',
  'description' => 'Decorative and practical pieces chosen to complement your candles and make every small ritual feel more considered.',
  'empty_title' => 'The accessories collection is being prepared.',
];
require_once __DIR__ . '/../../app/Bootstrap/catalogue.php';

$view = $catalogueController->category($categoryPage, $_GET);
$categoryPage = $view['categoryPage'];
extract($view, EXTR_SKIP);

require VIEW_PATH . '/Pages/category.php';
