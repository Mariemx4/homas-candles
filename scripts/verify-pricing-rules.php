<?php

/**
 * FILE PURPOSE: Command-line verification launcher for Verify Pricing Rules.
 *
 * DATA FLOW: Run this wrapper from the project root; it delegates to the corresponding test while keeping a convenient scripts/ entry point.
 */

// Backward-compatible command; the test lives under tests/Unit.
require __DIR__ . '/../tests/Unit/verify-pricing-rules.php';
