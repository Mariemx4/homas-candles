<?php

/**
 * FILE PURPOSE: Runtime configuration for Catalogue.
 *
 * DATA FLOW: Bootstrap code reads this configuration before constructing application objects. Keep secrets environment-driven and keep request or rendering logic out of this layer.
 */
/**
 * Public catalogue filter options.
 *
 * These slugs mirror the reference data in app/database/seed.sql.
 * Replace this array with active product types from the database when the
 * catalogue repository is connected.
 */
// WHAT: the "Product type" dropdown options on every category page.
// WHY:  temporary hardcoded stand-in for a real query — see the docblock
//       above for what replaces this once product_types is read from the
//       database instead.
return [
  'flowers' => 'Flowers',
  'bubble' => 'Bubble',
  'in-container' => 'Container candle',
  'bouquet' => 'Bouquet',
  'wax-melt' => 'Wax melt',
  'other' => 'Other',
  'accessories' => 'Accessories',
];
