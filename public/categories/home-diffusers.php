<?php


// WHAT: the Home Diffusers category page — see candles.php for the shared pattern.
$categoryPage = [
  'slug' => 'home-diffusers',
  'name' => 'Home diffusers',
  'path' => 'categories/home-diffusers.php',
  'eyebrow' => 'A softer atmosphere',
  'title' => 'Fragrance that quietly becomes part of your home.',
  'description' => 'Decorative home diffusers designed to fill your space with a gentle scent and the warm character of Hom\'As.',
  'empty_title' => 'The home diffuser collection is being prepared.',
];
require_once __DIR__ . '/../../app/Bootstrap/catalogue.php';

$view = $catalogueController->category($categoryPage, $_GET);
$categoryPage = $view['categoryPage'];
extract($view, EXTR_SKIP);

require VIEW_PATH . '/Pages/category.php';
