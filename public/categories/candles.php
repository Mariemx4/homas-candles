<?php

/**
 * FILE PURPOSE: Customer-facing category route for Candles.
 *
 * DATA FLOW: The web server invokes this compatibility/route file, which selects category input and delegates page construction to the shared catalogue flow.
 */
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

// ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.
require_once __DIR__ . '/../../app/Bootstrap/catalogue.php';

$view = $catalogueController->category($categoryPage, $_GET);
extract($view, EXTR_SKIP);

require __DIR__ . '/../../app/Views/Pages/category.php';
