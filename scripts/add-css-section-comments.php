<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Add local explanations at CSS dependency, base-rule, animation,
 * and responsive sections so stylesheets are understandable beyond one header.
 */

$directory = dirname(__DIR__) . '/public/assets/css';
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
);
$updatedFiles = 0;
$added = 0;

foreach ($files as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'css') {
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
    $baseSectionAdded = str_contains($contents, 'BASE RULES SECTION:');
    $importSectionAdded = str_contains($contents, 'DEPENDENCY SECTION:');
    foreach ($lines as $line) {
        $trimmed = trim($line);
        $comment = null;
        if (!$importSectionAdded && str_starts_with($trimmed, '@import ')) {
            $comment = 'DEPENDENCY SECTION: Load shared styles before this file’s own rules; later rules can override earlier ones.';
            $importSectionAdded = true;
        } elseif (preg_match('/^@media\s*\(([^)]+)\)/', $trimmed, $match) === 1) {
            $comment = 'RESPONSIVE SECTION: Override earlier layout rules when `' . $match[1] . '` matches the current device/viewport.';
        } elseif (preg_match('/^@keyframes\s+([A-Za-z0-9_-]+)/', $trimmed, $match) === 1) {
            $comment = 'ANIMATION SECTION: Define the named `' . $match[1] . '` motion timeline referenced by animation properties.';
        } elseif (!$baseSectionAdded
            && $trimmed !== ''
            && !str_starts_with($trimmed, '/*')
            && !str_starts_with($trimmed, '*')
            && !str_starts_with($trimmed, '*/')
            && !str_starts_with($trimmed, '@')
            && str_contains($trimmed, '{')
        ) {
            $comment = 'BASE RULES SECTION: Define the default appearance/layout before later state and responsive overrides.';
            $baseSectionAdded = true;
        }

        if ($comment !== null) {
            $previous = count($output) - 1;
            while ($previous >= 0 && trim($output[$previous]) === '') {
                $previous--;
            }
            $marker = explode(':', $comment, 2)[0] . ':';
            if ($previous < 0 || !str_contains($output[$previous], $marker)) {
                $output[] = '/* ' . $comment . ' */';
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

echo "Added {$added} CSS section explanations across {$updatedFiles} files.\n";

