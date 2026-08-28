<?php
// APP_BASE_URL can override discovery, for example "/homas-candles/public/".
// Otherwise derive the public directory from the executing web script. This
// supports both XAMPP subfolder URLs and a virtual host rooted at public/.
$configuredBaseUrl = getenv('APP_BASE_URL');
$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$publicMarker = '/public/';
$publicPosition = strpos($scriptName, $publicMarker);

if (is_string($configuredBaseUrl) && preg_match('#^/(?!/)[^\r\n]*$#', $configuredBaseUrl) === 1) {
    $baseUrl = $configuredBaseUrl;
} elseif ($publicPosition !== false) {
    $baseUrl = substr($scriptName, 0, $publicPosition + strlen($publicMarker));
} else {
    $baseUrl = '/';
}

define('BASE_URL', rtrim($baseUrl, '/') . '/');
