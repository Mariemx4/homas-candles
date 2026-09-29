<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Explain non-obvious PHP/database idioms beside the statements
 * where they occur: prepared SQL, transactions, validation filters, regular
 * expressions, collection transforms, password APIs, and match dispatch.
 */

$root = dirname(__DIR__);
$searchRoots = [$root . '/src', $root . '/app', $root . '/public'];
$updatedFiles = 0;
$added = 0;

foreach ($searchRoots as $searchRoot) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($searchRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($files as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $path = str_replace('\\', '/', $file->getPathname());
        if (str_contains($path, '/storage/')) {
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
            $trimmed = trim($line);
            $comment = null;
            if (str_contains($trimmed, '->prepare(')) {
                $comment = 'SQL IDIOM: Prepare SQL separately from its values; placeholders prevent input from becoming executable SQL.';
            } elseif (str_contains($trimmed, 'transactions->run(function')) {
                $comment = 'TRANSACTION IDIOM: Run related writes as one unit; an exception rolls back every write in this closure.';
            } elseif (str_contains($trimmed, 'filter_var(')) {
                $comment = 'VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.';
            } elseif (str_contains($trimmed, 'preg_match(')) {
                $comment = 'VALIDATION IDIOM: Test the complete string against a regular-expression format before accepting it.';
            } elseif (str_contains($trimmed, 'array_map(')) {
                $comment = 'COLLECTION IDIOM: Transform every array item and return a new array without changing the original one.';
            } elseif (str_contains($trimmed, 'array_filter(')) {
                $comment = 'COLLECTION IDIOM: Keep only array items for which the callback returns true.';
            } elseif (str_contains($trimmed, 'array_reduce(')) {
                $comment = 'COLLECTION IDIOM: Fold many array items into one accumulated result (for example, an all-items boolean).';
            } elseif (str_contains($trimmed, 'password_verify(')) {
                $comment = 'PASSWORD IDIOM: Compare plain submitted text with the stored one-way hash without decrypting the hash.';
            } elseif (str_contains($trimmed, 'password_hash(')) {
                $comment = 'PASSWORD IDIOM: Store a slow one-way password hash, never the original password text.';
            } elseif (preg_match('/\bmatch\s*\(/', $trimmed) === 1) {
                $comment = 'BRANCHING IDIOM: `match` selects exactly one result using strict comparisons and rejects/handles a default explicitly.';
            } elseif (str_contains($trimmed, 'http_build_query(')) {
                $comment = 'URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.';
            }

            if ($comment !== null) {
                $previous = count($output) - 1;
                while ($previous >= 0 && trim($output[$previous]) === '') {
                    $previous--;
                }
                $alreadyExplained = $previous >= 0
                    && (str_starts_with(trim($output[$previous]), '//')
                        || str_starts_with(trim($output[$previous]), '/*')
                        || str_starts_with(trim($output[$previous]), '*'));
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
            throw new RuntimeException('Could not update ' . $path);
        }
        $updatedFiles++;
    }
}

echo "Added {$added} local PHP idiom explanations across {$updatedFiles} files.\n";

