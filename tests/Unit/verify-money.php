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

// The signed/stored helpers replaced private copies in several services. These
// reference functions are the removed implementations, kept here so the shared
// helpers are proven to behave identically for representative inputs.
$oldSignedParse = static function (string $value): ?int {
    if (preg_match('/^(-?)(?:0|[1-9][0-9]{0,8})(?:\.([0-9]{1,3}))?$/', $value, $matches) !== 1) {
        return null;
    }
    $sign = $matches[1] === '-' ? -1 : 1;
    return $sign * (((int) explode('.', ltrim($value, '-'))[0] * 1000) + (int) str_pad($matches[2] ?? '', 3, '0'));
};
$oldSignedFormat = static function (int $millis): string {
    $sign = $millis < 0 ? '-' : '';
    $millis = abs($millis);
    return $sign . intdiv($millis, 1000) . '.' . str_pad((string) ($millis % 1000), 3, '0', STR_PAD_LEFT);
};
$oldStored = static function (string $amount, bool $signed): int {
    $pattern = $signed ? '/^(-?)(\d+)(?:\.(\d{1,3}))?$/' : '/^()(\d+)(?:\.(\d{1,3}))?$/';
    if (preg_match($pattern, $amount, $matches) !== 1) {
        return 0;
    }
    $sign = $matches[1] === '-' ? -1 : 1;
    return $sign * (((int) $matches[2] * 1000) + (int) str_pad($matches[3] ?? '', 3, '0'));
};
$samples = ['', '-', '--1', '0', '-0', '1', '-1', '01', '-01', '1.5', '-1.5', '0.001', '-0.001', '12.345', '1.2345',
    '999999999.999', '-999999999.999', '1000000000', 'abc', '1,5', ' 1', '00.500'];
foreach ($samples as $sample) {
    if (Money::parseSignedMillis($sample) !== $oldSignedParse($sample)) {
        throw new RuntimeException('parseSignedMillis changed behavior for "' . $sample . '"');
    }
    if (Money::storedMillis($sample) !== $oldStored($sample, false)) {
        throw new RuntimeException('storedMillis changed behavior for "' . $sample . '"');
    }
    if (Money::storedSignedMillis($sample) !== $oldStored($sample, true)) {
        throw new RuntimeException('storedSignedMillis changed behavior for "' . $sample . '"');
    }
}
foreach ([0, 1, 999, 1000, 1500, 12345, -1, -999, -1000, -1500, -12345] as $millis) {
    if (Money::formatSignedMillis($millis) !== $oldSignedFormat($millis)) {
        throw new RuntimeException('formatSignedMillis changed behavior for ' . $millis);
    }
}

echo "Signed and stored money helper checks passed.\n";

// remainingAmount/signedDifference replaced four private subtractMoney() copies.
foreach ([['100.000', '40.500', '59.500'], ['40.000', '100.000', '0.000'], ['0.005', '0', '0.005'], ['bad', '1', '0.000']] as [$total, $paid, $expected]) {
    if (Money::remainingAmount($total, $paid) !== $expected) {
        throw new RuntimeException("remainingAmount($total, $paid) should be $expected");
    }
}
foreach ([['10.000', '12.250', '-2.250'], ['12.250', '10.000', '2.250'], ['-1.000', '1.000', '-2.000']] as [$a, $b, $expected]) {
    if (Money::signedDifference($a, $b) !== $expected) {
        throw new RuntimeException("signedDifference($a, $b) should be $expected");
    }
}
echo "Remaining-amount and signed-difference checks passed.\n";
