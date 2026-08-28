<?php
// WHAT: the Event Favors category page — see candles.php for the shared pattern.
$categoryPage = [
  'slug' => 'event-favors',
  'name' => 'Event Favors',
  'path' => 'categories/event-favors.php',
  'eyebrow' => 'Made for your occasion',
  'title' => 'Small handmade keepsakes with a personal meaning.',
  'description' => 'Thoughtful candle favours created for weddings, celebrations, and gatherings that deserve a beautiful finishing touch.',
  'empty_title' => 'The event favors collection is being prepared.',
];

require_once __DIR__ . '/../../app/Bootstrap/catalogue.php';

$view = $catalogueController->category($categoryPage, $_GET);
extract($view, EXTR_SKIP);

require __DIR__ . '/../../app/Views/Pages/category.php';
