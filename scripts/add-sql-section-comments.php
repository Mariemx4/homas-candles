<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Add local explanations before major SQL schema and data blocks.
 *
 * SQL files are often read independently from PHP. These comments identify why
 * each CREATE/ALTER/INSERT/UPDATE section exists and how repositories depend on
 * the resulting database structure.
 */

$root = dirname(__DIR__);
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
$excluded = ['/.git/', '/vendor/', '/app/storage/'];
$updatedFiles = 0;
$added = 0;

foreach ($files as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'sql') {
        continue;
    }
    $path = str_replace('\\', '/', $file->getPathname());
    if (array_filter($excluded, static fn (string $part): bool => str_contains($path, $part)) !== []) {
        continue;
    }

    $contents = file_get_contents($file->getPathname());
    if ($contents === false) {
        throw new RuntimeException('Could not read ' . $path);
    }
    $newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";
    $endsWithNewline = str_ends_with($contents, "\n") || str_ends_with($contents, "\r");
    $lines = preg_split('/\R/', $contents);
    if ($lines === false) {
        continue;
    }

    $output = [];
    $changed = false;
    foreach ($lines as $line) {
        $comment = null;
        if (preg_match('/^\s*CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?([A-Za-z0-9_]+)`?/i', $line, $match) === 1) {
            $comment = "Define the `{$match[1]}` table, its valid columns, and integrity constraints used by repositories.";
        } elseif (preg_match('/^\s*ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?/i', $line, $match) === 1) {
            $comment = "Evolve the existing `{$match[1]}` table while preserving previously stored data.";
        } elseif (preg_match('/^\s*CREATE\s+(?:UNIQUE\s+)?INDEX\s+`?([A-Za-z0-9_]+)`?/i', $line, $match) === 1) {
            $comment = "Add the `{$match[1]}` index to enforce uniqueness or speed the repository queries that use its columns.";
        } elseif (preg_match('/^\s*INSERT\s+INTO\s+`?([A-Za-z0-9_]+)`?/i', $line, $match) === 1) {
            $comment = "Insert required reference/development rows into `{$match[1]}` for application lookups.";
        } elseif (preg_match('/^\s*UPDATE\s+`?([A-Za-z0-9_]+)`?/i', $line, $match) === 1) {
            $comment = "Transform existing `{$match[1]}` rows so they satisfy the new schema or business rule.";
        }

        if ($comment !== null) {
            $previous = count($output) - 1;
            while ($previous >= 0 && trim($output[$previous]) === '') {
                $previous--;
            }
            if ($previous < 0 || !str_starts_with(trim($output[$previous]), '-- SECTION PURPOSE:')) {
                $output[] = '-- SECTION PURPOSE: ' . $comment;
                $changed = true;
                $added++;
            }
        }
        $output[] = $line;
    }

    if (!$changed) {
        continue;
    }
    if ($endsWithNewline && end($output) === '') {
        array_pop($output);
    }
    if (file_put_contents($file->getPathname(), implode($newline, $output) . ($endsWithNewline ? $newline : '')) === false) {
        throw new RuntimeException('Could not update ' . $path);
    }
    $updatedFiles++;
}

echo "Added {$added} SQL section explanations across {$updatedFiles} files.\n";

