<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap/autoload.php';

use App\Services\DeadlinePresentation;

// Includes a DST transition to ensure these are calendar days, not hours / 24.
$today = new DateTimeImmutable('2026-03-08 23:30:00', new DateTimeZone('America/New_York'));
foreach ([
    ['2026-03-06', 'Overdue by 2 days', 'danger'],
    ['2026-03-07', 'Overdue by 1 day', 'danger'],
    ['2026-03-08', 'Today', 'warning'],
    ['2026-03-09', 'Tomorrow', 'neutral'],
    ['2026-03-11', 'In 3 days', 'neutral'],
    [null, 'Not set', 'neutral'],
    ['2026-02-30', 'Not set', 'neutral'],
] as [$date, $label, $tone]) {
    if (DeadlinePresentation::forDate($date, $today) !== ['label' => $label, 'tone' => $tone]) {
        throw new RuntimeException('Unexpected deadline for ' . ($date ?? 'NULL'));
    }
}
echo "[PASS] Deadline labels handle calendar boundaries, missing dates, invalid dates and the supplied timezone.\n";
