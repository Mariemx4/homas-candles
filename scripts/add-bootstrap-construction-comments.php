<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Explain individual dependency-construction lines in bootstrap
 * files so a beginner can see why each `new Class(...)` object is created.
 */

$directory = dirname(__DIR__) . '/app/Bootstrap';
$files = new DirectoryIterator($directory);
$updatedFiles = 0;
$added = 0;

foreach ($files as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
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
        if (preg_match('/^(\s*)\$[A-Za-z_][A-Za-z0-9_]*\s*=\s*new\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*\(/', $line, $match) === 1) {
            $class = substr($match[2], strrpos('\\' . $match[2], '\\'));
            $reason = match (true) {
                str_ends_with($class, 'Repository') => 'give the service a dedicated database-access object',
                str_ends_with($class, 'Service') => 'apply this feature’s business and validation rules',
                str_ends_with($class, 'Controller') => 'provide the public route with its request-facing coordinator',
                str_ends_with($class, 'Session') => 'read and update this identity’s session state',
                str_contains($class, 'Authorization') => 'enforce access rules on protected routes',
                str_contains($class, 'Csrf') => 'protect state-changing forms from cross-site request forgery',
                str_contains($class, 'Transaction') => 'commit related database changes together or roll them all back',
                str_contains($class, 'Storage') => 'keep file validation and filesystem paths out of controllers',
                default => 'supply one reusable dependency to the objects constructed later',
            };
            $previous = count($output) - 1;
            while ($previous >= 0 && trim($output[$previous]) === '') {
                $previous--;
            }
            $alreadyExplained = $previous >= 0
                && (str_starts_with(trim($output[$previous]), '//') || str_starts_with(trim($output[$previous]), '/*'));
            if (!$alreadyExplained) {
                $output[] = $match[1] . "// DEPENDENCY PURPOSE: Create {$class} to {$reason}.";
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

echo "Added {$added} dependency-construction explanations across {$updatedFiles} bootstrap files.\n";
