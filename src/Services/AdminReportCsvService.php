<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Business service for Admin Report Csv Service; it contains application rules and coordinates related persistence work.
 *
 * DATA FLOW: Controllers call this layer. It validates and normalizes domain input, calls repositories or storage collaborators, and returns structured results without rendering HTML.
 */

namespace App\Services;

final class AdminReportCsvService
{
    /**
     * FUNCTION PURPOSE: Apply the operation represented by `safeCell` (safe cell) and return its result.
     */
    public function safeCell(mixed $value): string
    {
        $cell = str_replace(["\r\n", "\r"], "\n", (string) ($value ?? ''));
        // VALIDATION IDIOM: Test the complete string against a regular-expression format before accepting it.
        if (preg_match('/^[\x00-\x20]*[=+\-@]/u', $cell) === 1) {
            return "'" . $cell;
        }

        return $cell;
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `write` (write) and return its result.
     *
     * @param resource $stream @param array<string, string> $columns @param list<array<string, mixed>> $rows
     */
    public function write($stream, array $columns, array $rows): void
    {
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, array_values($columns), ',', '"', '');
        foreach ($rows as $row) {
            $values = [];
            foreach (array_keys($columns) as $key) {
                $values[] = $this->safeCell($row[$key] ?? '');
            }
            fputcsv($stream, $values, ',', '"', '');
        }
    }
}
