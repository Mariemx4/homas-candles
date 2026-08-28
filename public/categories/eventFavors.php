<?php

declare(strict_types=1);

// Legacy URL retained for bookmarks and external links.
$location = 'event-favors.php';
$queryString = (string) ($_SERVER['QUERY_STRING'] ?? '');
if ($queryString !== '') {
    $location .= '?' . $queryString;
}

header('Location: ' . $location, true, 301);
exit;
