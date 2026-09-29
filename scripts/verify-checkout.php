<?php

/**
 * FILE PURPOSE: Command-line verification launcher for Verify Checkout.
 *
 * DATA FLOW: Run this wrapper from the project root; it delegates to the corresponding test while keeping a convenient scripts/ entry point.
 */

// Backward-compatible command; the test lives under tests/Integration.
require __DIR__ . '/../tests/Integration/verify-checkout.php';
