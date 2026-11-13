<?php


// WHAT: the Candle Sets category page — see candles.php for the shared pattern.
$categoryPage = [
  'slug' => 'candle-sets',
  'name' => 'Candle sets',
  'path' => 'categories/candle-sets.php',
  'eyebrow' => 'Beautifully paired',
  'title' => 'Thoughtful sets, composed for warmth and harmony.',
  'description' => 'Curated candle combinations designed to feel effortless in your home and meaningful when given as a gift.',
  'empty_title' => 'The candle set collection is being prepared.',
];
require_once __DIR__ . '/../../app/Bootstrap/catalogue.php';

$view = $catalogueController->category($categoryPage, $_GET);
$categoryPage = $view['categoryPage'];
extract($view, EXTR_SKIP);

require VIEW_PATH . '/Pages/category.php';
