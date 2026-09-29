<?php

/**
 * FILE PURPOSE: Runtime configuration for Config.
 *
 * DATA FLOW: Bootstrap code reads this configuration before constructing application objects. Keep secrets environment-driven and keep request or rendering logic out of this layer.
 */
// APP_BASE_URL can override discovery, for example "/homas-candles/public/".
// Otherwise derive the public directory from the executing web script. This
// supports both XAMPP subfolder URLs and a virtual host rooted at public/.
$configuredBaseUrl = getenv('APP_BASE_URL');
$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$publicMarker = '/public/';
$publicPosition = strpos($scriptName, $publicMarker);

// VALIDATION IDIOM: Test the complete string against a regular-expression format before accepting it.
if (is_string($configuredBaseUrl) && preg_match('#^/(?!/)[^\r\n]*$#', $configuredBaseUrl) === 1) {
    $baseUrl = $configuredBaseUrl;
} elseif ($publicPosition !== false) {
    $baseUrl = substr($scriptName, 0, $publicPosition + strlen($publicMarker));
} else {
    $baseUrl = '/';
}

define('BASE_URL', rtrim($baseUrl, '/') . '/');
