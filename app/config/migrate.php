<?php

/**
 * FILE PURPOSE: Runtime configuration for Migrate.
 *
 * DATA FLOW: Bootstrap code reads this configuration before constructing application objects. Keep secrets environment-driven and keep request or rendering logic out of this layer.
 */

// Backward-compatible command. Operational scripts now live in scripts/.
require __DIR__ . '/../../scripts/migrate.php';
