<?php

/**
 * FILE PURPOSE: Customer-facing category route for Accessories.
 *
 * DATA FLOW: The web server invokes this compatibility/route file, which selects category input and delegates page construction to the shared catalogue flow.
 */
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

// ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.
require_once __DIR__ . '/../../app/Bootstrap/catalogue.php';

$view = $catalogueController->category($categoryPage, $_GET);
$categoryPage = $view['categoryPage'];
extract($view, EXTR_SKIP);

require __DIR__ . '/../../app/Views/Pages/category.php';
