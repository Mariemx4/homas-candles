<?php

/**
 * FILE PURPOSE: Customer-facing category route for Candle Sets.
 *
 * DATA FLOW: The web server invokes this compatibility/route file, which selects category input and delegates page construction to the shared catalogue flow.
 */
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

// ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.
require_once __DIR__ . '/../../app/Bootstrap/catalogue.php';

$view = $catalogueController->category($categoryPage, $_GET);
extract($view, EXTR_SKIP);

require __DIR__ . '/../../app/Views/Pages/category.php';
