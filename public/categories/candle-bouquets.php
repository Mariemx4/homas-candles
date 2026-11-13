<?php


// WHAT: the Candle Bouquets category page — see candles.php for the shared pattern.
$categoryPage = [
  'slug' => 'candle-bouquets',
  'name' => 'Candle bouquets',
  'path' => 'categories/candle-bouquets.php',
  'eyebrow' => 'A lasting gesture',
  'title' => 'Bouquets where flowers, fragrance, and light meet.',
  'description' => 'Handcrafted candle arrangements made to celebrate love, gratitude, and the moments you want someone to remember.',
  'empty_title' => 'The candle bouquet collection is being prepared.',
];
require_once __DIR__ . '/../../app/Bootstrap/catalogue.php';

$view = $catalogueController->category($categoryPage, $_GET);
$categoryPage = $view['categoryPage'];
extract($view, EXTR_SKIP);

require VIEW_PATH . '/Pages/category.php';
