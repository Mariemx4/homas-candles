<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap/autoload.php';

use App\Services\Money;

foreach (['0' => 0, '0.001' => 1, '12.3' => 12300, '999999999.999' => 999999999999] as $text => $millis) {
    if (Money::parseMillis((string) $text) !== $millis
        || Money::parseMillis(Money::formatMillis($millis)) !== $millis) {
        throw new RuntimeException('Money round trip failed for ' . $text);
    }
}
foreach (['', '-1', '01', '1.2345', '1,000', '1000000000'] as $invalid) {
    if (Money::parseMillis($invalid) !== null) {
        throw new RuntimeException('Invalid money was accepted: ' . $invalid);
    }
}

echo "Money parsing checks passed.\n";
