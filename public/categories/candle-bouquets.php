<?php

/**
 * FILE PURPOSE: Customer-facing category route for Candle Bouquets.
 *
 * DATA FLOW: The web server invokes this compatibility/route file, which selects category input and delegates page construction to the shared catalogue flow.
 */
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

// ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.
require_once __DIR__ . '/../../app/Bootstrap/catalogue.php';

$view = $catalogueController->category($categoryPage, $_GET);
extract($view, EXTR_SKIP);

require __DIR__ . '/../../app/Views/Pages/category.php';
