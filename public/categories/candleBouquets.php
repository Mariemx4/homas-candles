<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Customer-facing category route for Candle Bouquets.
 *
 * DATA FLOW: The web server invokes this compatibility/route file, which selects category input and delegates page construction to the shared catalogue flow.
 */

// Legacy URL retained for bookmarks and external links.
$location = 'candle-bouquets.php';
$queryString = (string) ($_SERVER['QUERY_STRING'] ?? '');
if ($queryString !== '') {
    $location .= '?' . $queryString;
}

// RESPONSE STEP: Redirect after processing so browser refresh repeats a GET rather than submitting the form again.
header('Location: ' . $location, true, 301);
exit;
