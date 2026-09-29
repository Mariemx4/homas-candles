<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Explain setup, execution, and result phases inside maintenance
 * scripts and executable tests, where there is no HTML structure to guide a
 * beginner reader.
 */

$root = dirname(__DIR__);
$roots = [$root . '/scripts', $root . '/tests'];
$updatedFiles = 0;
$added = 0;

foreach ($roots as $searchRoot) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($searchRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($files as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        // Comment-generation utilities already explain themselves and are not tests.
        if (str_starts_with($file->getFilename(), 'add-')) {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        if ($contents === false) {
            throw new RuntimeException('Could not read ' . $file->getPathname());
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
            $trimmed = trim($line);
            $comment = null;
            if (preg_match('/^require(?:_once)?\s/', $trimmed) === 1) {
                $comment = 'SETUP STEP: Load the application code or delegated test required before this command can run.';
            } elseif (preg_match('/^\$(?:checks|tests|cases)\s*=\s*\[/', $trimmed) === 1) {
                $comment = 'TEST CASES: Define the independent expectations that this executable verification will evaluate.';
            } elseif (preg_match('/^foreach\s*\(\$(?:checks|tests|cases)/', $trimmed) === 1) {
                $comment = 'EXECUTION STEP: Run every declared case so one failure does not hide the remaining results.';
            } elseif (str_contains($trimmed, '= database(') || str_contains($trimmed, '= new PDO(')) {
                $comment = 'DATABASE SETUP: Open the real test/development connection used by this integration operation.';
            } elseif (str_contains($trimmed, 'beginTransaction(')) {
                $comment = 'ISOLATION STEP: Start a transaction so test changes can be rolled back instead of polluting development data.';
            } elseif (preg_match('/^exit\s*\(/', $trimmed) === 1) {
                $comment = 'RESULT STEP: Return a process status code; zero means success and non-zero lets automation detect failure.';
            }

            if ($comment !== null) {
                $previous = count($output) - 1;
                while ($previous >= 0 && trim($output[$previous]) === '') {
                    $previous--;
                }
                $alreadyExplained = $previous >= 0 && str_starts_with(trim($output[$previous]), '//');
                if (!$alreadyExplained) {
                    $indent = substr($line, 0, strlen($line) - strlen(ltrim($line)));
                    $output[] = $indent . '// ' . $comment;
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
            throw new RuntimeException('Could not update ' . $file->getPathname());
        }
        $updatedFiles++;
    }
}

echo "Added {$added} script/test phase explanations across {$updatedFiles} files.\n";

