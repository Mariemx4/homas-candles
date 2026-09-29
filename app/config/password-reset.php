<?php

/**
 * FILE PURPOSE: Runtime configuration for Password Reset.
 *
 * DATA FLOW: Bootstrap code reads this configuration before constructing application objects. Keep secrets environment-driven and keep request or rendering logic out of this layer.
 */

// Backward-compatible entry point. New code uses app/Bootstrap/password-reset.php.
require_once __DIR__ . '/../Bootstrap/password-reset.php';
