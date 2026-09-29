<?php

/**
 * FILE PURPOSE: Command-line verification launcher for Verify Password Reset.
 *
 * DATA FLOW: Run this wrapper from the project root; it delegates to the corresponding test while keeping a convenient scripts/ entry point.
 */

// Backward-compatible command; the test now lives under tests/Integration.
require __DIR__ . '/../tests/Integration/verify-password-reset.php';

