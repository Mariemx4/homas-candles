<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Command-line verification launcher for Verify Order Status Workflow.
 *
 * DATA FLOW: Run this wrapper from the project root; it delegates to the corresponding test while keeping a convenient scripts/ entry point.
 */

// SETUP STEP: Load the application code or delegated test required before this command can run.
require __DIR__ . '/../tests/Integration/verify-order-status-workflow.php';
