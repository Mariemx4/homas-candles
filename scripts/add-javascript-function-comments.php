<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Add local purpose comments to named JavaScript functions and
 * named arrow functions that do not already have an explanation.
 *
 * Browser scripts contain many small interaction helpers. Reading the comment
 * beside each helper makes the event flow understandable without first tracing
 * every caller. Existing comments are preserved.
 */

$directory = dirname(__DIR__) . '/public/assets/js';

$words = static function (string $name): string {
    $name = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $name) ?? $name;

    return strtolower(str_replace(['_', '-'], ' ', $name));
};

$purpose = static function (string $name) use ($words): string {
    $rules = [
        'init' => 'Find the relevant page elements and attach the %s behavior.',
        'create' => 'Create and return a reusable %s helper with private state.',
        'open' => 'Open %s and update its accessibility/focus state.',
        'close' => 'Close %s and restore its neutral accessibility/focus state.',
        'show' => 'Display %s and synchronize the visible interface.',
        'hide' => 'Hide %s and synchronize the visible interface.',
        'update' => 'Recalculate and render %s from the current inputs/state.',
        'sync' => 'Synchronize %s so the native control and custom interface agree.',
        'validate' => 'Validate %s and expose a useful error state when needed.',
        'get' => 'Read and return %s without changing application state.',
        'set' => 'Set %s and update the related interface state.',
        'format' => 'Convert %s into the text format shown or submitted by the UI.',
        'normalize' => 'Convert %s into a valid consistent value.',
        'compact' => 'Remove display formatting from %s to produce a normalized value.',
        'select' => 'Select %s and propagate the choice to the real form control.',
        'place' => 'Measure available space and place %s where it remains visible.',
        'trap' => 'Keep keyboard focus inside %s while it behaves like a modal.',
        'distribute' => 'Distribute %s across the related input controls.',
        'fields' => 'Check whether %s contains meaningful user input.',
    ];

    foreach ($rules as $prefix => $template) {
        if (str_starts_with(strtolower($name), $prefix)) {
            $subject = trim($words(substr($name, strlen($prefix))));

            return sprintf($template, $subject !== '' ? $subject : 'the supplied value');
        }
    }

    return 'Handle ' . $words($name) . ' as one named browser-interaction step.';
};

$files = new DirectoryIterator($directory);
$updatedFiles = 0;
$added = 0;
foreach ($files as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'js') {
        continue;
    }

    $contents = file_get_contents($file->getPathname());
    if ($contents === false) {
        throw new RuntimeException('Could not read ' . $file->getPathname());
    }
    $newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";
    $beforeCleanup = $contents;
    // Collapse duplicates created by the first version of this idempotency check.
    $contents = preg_replace('/^([ \t]*\/\*\* FUNCTION PURPOSE:.*\*\/)\R\1\R?/m', '$1' . $newline, $contents) ?? $contents;
    $cleanedDuplicates = $contents !== $beforeCleanup;
    $endsWithNewline = str_ends_with($contents, "\n") || str_ends_with($contents, "\r");
    $lines = preg_split('/\R/', $contents);
    if ($lines === false) {
        continue;
    }

    $output = [];
    $changed = $cleanedDuplicates;
    foreach ($lines as $line) {
        $match = null;
        if (preg_match('/^(\s*)(?:export\s+)?(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*\(/', $line, $functionMatch) === 1) {
            $match = [$functionMatch[1], $functionMatch[2]];
        } elseif (preg_match('/^(\s*)(?:const|let)\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?\(/', $line, $arrowMatch) === 1) {
            $match = [$arrowMatch[1], $arrowMatch[2]];
        }

        if ($match !== null) {
            $previous = count($output) - 1;
            while ($previous >= 0 && trim($output[$previous]) === '') {
                $previous--;
            }
            $alreadyExplained = $previous >= 0
                && (str_starts_with(trim($output[$previous]), '//')
                    || str_starts_with(trim($output[$previous]), '/**')
                    || trim($output[$previous]) === '*/');
            if (!$alreadyExplained) {
                $output[] = $match[0] . '/** FUNCTION PURPOSE: ' . $purpose($match[1]) . ' */';
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

echo "Added {$added} JavaScript function explanations across {$updatedFiles} files.\n";
