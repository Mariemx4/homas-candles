<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap/autoload.php';

// Preserve this public bookmark; the shared helper owns query-safe redirection.
\App\Http\LegacyCategoryRedirect::send('candleBouquets.php');
