<?php

declare(strict_types=1);

/**
 * The `$check(condition, message)` function every verification suite uses.
 *
 * A failed check throws RuntimeException with the message, which stops the
 * suite (its `finally` block still cleans up). Pick the variant that matches
 * how the suite reports passing checks:
 *
 *     $check = TestCheck::quiet();               // print nothing, only fail
 *     $check = TestCheck::printing();            // print "[PASS] message"
 *     $check = TestCheck::printing($checks);     // ... and count into $checks
 *     $check = TestCheck::collecting($results);  // add "[PASS] message" to $results
 */
final class TestCheck
{
    public static function quiet(): Closure
    {
        return static function (bool $condition, string $message): void {
            if (!$condition) {
                throw new RuntimeException($message);
            }
        };
    }

    /** @param int|null $count incremented for every passing check (pass the suite's counter) */
    public static function printing(?int &$count = null): Closure
    {
        return static function (bool $condition, string $message) use (&$count): void {
            if (!$condition) {
                throw new RuntimeException($message);
            }
            $count = ($count ?? 0) + 1;
            echo '[PASS] ' . $message . PHP_EOL;
        };
    }

    /** @param list<string> $results receives one "[PASS] message" line per passing check */
    public static function collecting(?array &$results): Closure
    {
        return static function (bool $condition, string $message) use (&$results): void {
            if (!$condition) {
                throw new RuntimeException($message);
            }
            $results[] = '[PASS] ' . $message;
        };
    }
}
