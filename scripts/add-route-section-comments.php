<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Add request-flow comments beside important operations in public
 * PHP entry points: bootstrap, authorization, request branching, redirecting,
 * view selection, caching, and layout rendering.
 */

$root = dirname(__DIR__) . '/public';
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
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
        $trimmed = trim($line);
        $comment = null;
        if (str_contains($trimmed, 'require_once') && str_contains($trimmed, '/Bootstrap/')) {
            $comment = 'ROUTE STEP: Load dependency wiring; the required bootstrap exposes controllers and shared security objects as variables.';
        } elseif (str_contains($trimmed, 'requireActiveAdministrator()')) {
            $comment = 'ROUTE STEP: Stop or redirect unless the session belongs to an active administrator; keep the returned account for the layout.';
        } elseif (preg_match('/^if\s*\(.*REQUEST_METHOD.*===\s*[\'\"]POST[\'\"]/', $trimmed) === 1) {
            $comment = 'REQUEST BRANCH: POST represents a state-changing form submission, so validate security/input before calling its service action.';
        } elseif (preg_match('/^if\s*\(.*REQUEST_METHOD.*!==\s*[\'\"]GET[\'\"]/', $trimmed) === 1) {
            $comment = 'REQUEST GUARD: Reject HTTP methods this route does not implement instead of handling them accidentally.';
        } elseif (str_contains($trimmed, "header('Location:") || str_contains($trimmed, 'header("Location:')) {
            $comment = 'RESPONSE STEP: Redirect after processing so browser refresh repeats a GET rather than submitting the form again.';
        } elseif (str_contains($trimmed, "header('Cache-Control:") || str_contains($trimmed, 'header("Cache-Control:')) {
            $comment = 'RESPONSE POLICY: Tell browsers/proxies how this private or changing response may be cached.';
        } elseif (str_starts_with($trimmed, '$contentView =')) {
            $comment = 'RENDER STEP: Select the inner page template; the layout will require this path inside its shared HTML shell.';
        } elseif (str_contains($trimmed, 'Views/Layouts/') && preg_match('/^require\s/', $trimmed) === 1) {
            $comment = 'RENDER STEP: Load the shared layout last, after all variables required by the selected page view have been prepared.';
        }

        if ($comment !== null) {
            $previous = count($output) - 1;
            while ($previous >= 0 && trim($output[$previous]) === '') {
                $previous--;
            }
            $alreadyExplained = $previous >= 0
                && (str_starts_with(trim($output[$previous]), '//') || str_starts_with(trim($output[$previous]), '/*'));
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

echo "Added {$added} request-flow explanations across {$updatedFiles} public PHP files.\n";

