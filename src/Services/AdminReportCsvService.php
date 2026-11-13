<?php

declare(strict_types=1);

namespace App\Services;

final class AdminReportCsvService
{
    public function safeCell(mixed $value): string
    {
        $cell = str_replace(["\r\n", "\r"], "\n", (string) ($value ?? ''));
        if (preg_match('/^[\x00-\x20]*[=+\-@]/u', $cell) === 1) {
            return "'" . $cell;
        }

        return $cell;
    }

    /**
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
