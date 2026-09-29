<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Add beginner-oriented comments beside named PHP functions that
 * do not already have a local explanation.
 *
 * This is intentionally narrower than file headers: it places purpose and layer
 * guidance where a reader encounters each function. Existing comments win and
 * are never replaced. Re-running the script is idempotent.
 */

$root = dirname(__DIR__);

/** Turn camelCase or snake_case identifiers into readable lowercase words. */
$words = static function (string $identifier): string {
    $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $identifier) ?? $identifier;

    return strtolower(str_replace('_', ' ', $spaced));
};

/** Describe a method from its verb and subject instead of merely repeating its name. */
$purpose = static function (string $name) use ($words): string {
    if ($name === '__construct') {
        return 'Receive and store the dependencies this object needs; the bootstrap file supplies them.';
    }

    $rules = [
        'normalize' => 'Validate and convert %s into a consistent internal shape.',
        'validate' => 'Check %s against the application rules and return usable values or errors.',
        'find' => 'Fetch %s and return the matching data when it exists.',
        'count' => 'Count %s without loading complete records unnecessarily.',
        'list' => 'Prepare a filtered and paginated collection from the supplied query options.',
        'detail' => 'Load and assemble all data needed by one detail screen.',
        'overview' => 'Assemble the counts and short lists needed by an overview screen.',
        'summary' => 'Convert the supplied record into a compact summary for the caller.',
        'save' => 'Validate and persist %s.',
        'insert' => 'Insert %s as a new database record.',
        'update' => 'Update the stored %s values.',
        'upsert' => 'Insert %s or update the existing matching record.',
        'delete' => 'Delete %s from persistent storage.',
        'remove' => 'Remove %s and any relationship-specific state it owns.',
        'add' => 'Add %s after enforcing the relevant rules.',
        'set' => 'Set the stored %s value explicitly.',
        'toggle' => 'Switch %s to the requested boolean state after validation.',
        'process' => 'Handle the %s request branch and return a consistent result.',
        'transition' => 'Move %s to an allowed next state and record the change.',
        'record' => 'Validate and record %s as a new event or movement.',
        'resolve' => 'Resolve %s into the safe concrete value needed by the caller.',
        'parse' => 'Parse %s text without accepting unsupported formats.',
        'format' => 'Format %s into its display representation.',
        'build' => 'Build %s from already validated inputs.',
        'create' => 'Create %s and return its resulting data.',
        'query' => 'Read and normalize the %s query value.',
        'escape' => 'Escape %s so special characters are treated literally.',
        'assert' => 'Stop the operation unless %s satisfies the required rule.',
        'allow' => 'Accept %s only when it belongs to the allowed set.',
        'allowed' => 'Accept %s only when it belongs to the allowed set.',
        'positive' => 'Convert %s into a positive integer or reject it.',
        'money' => 'Convert %s using integer thousandths to avoid floating-point errors.',
        'millis' => 'Convert %s between integer thousandths and a money string.',
        'subtract' => 'Subtract %s using exact integer money arithmetic.',
        'length' => 'Count characters in %s safely for UTF-8 validation.',
        'is' => 'Answer whether %s satisfies this condition.',
        'has' => 'Answer whether %s exists or is present.',
        'can' => 'Answer whether %s is currently permitted.',
        'exists' => 'Check whether %s exists without loading unnecessary data.',
        'verify' => 'Verify %s and persist the approved result.',
        'reject' => 'Reject %s while preserving the reason and audit state.',
        'assign' => 'Assign %s to the selected owner after validation.',
        'convert' => 'Convert %s into the target domain record as one controlled operation.',
        'upload' => 'Validate, store, and attach the uploaded %s file.',
        'discard' => 'Safely remove the stored %s file when it is no longer referenced.',
        'failure' => 'Build a consistent failure result for %s.',
        'defaults' => 'Build safe default values for %s.',
        'confirmation' => 'Build confirmation data for %s before a state change.',
    ];

    foreach ($rules as $prefix => $template) {
        if (str_starts_with(strtolower($name), $prefix)) {
            $subjectName = substr($name, strlen($prefix));
            $subject = trim($words($subjectName));

            return sprintf($template, $subject !== '' ? $subject : 'the supplied value');
        }
    }

    $readableName = $words($name);

    return match (true) {
        str_ends_with($name, 'Summary') => 'Build the ' . $readableName . ' data needed by its caller.',
        str_ends_with($name, 'Exists') => 'Check whether ' . $readableName . ' without loading a complete record.',
        str_ends_with($name, 'Path') => 'Resolve the ' . $readableName . ' value used for safe file access.',
        default => 'Apply the operation represented by `' . $name . '` (' . $readableName . ') and return its result.',
    };
};

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)
);
$updatedFiles = 0;
$addedComments = 0;

foreach ($files as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $path = str_replace('\\', '/', $file->getPathname());
    $contents = file_get_contents($file->getPathname());
    if ($contents === false) {
        throw new RuntimeException('Could not read ' . $path);
    }

    $newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";
    $beforeCleanup = $contents;

    // A hand-written one-line explanation is better than a generated purpose.
    // Remove the generated-only block when both ended up adjacent.
    $contents = preg_replace(
        '/^([ \t]*\/\*\*(?![^\r\n]*@)[^\r\n]*\*\/)\R[ \t]*\/\*\*\R[ \t]*\* FUNCTION PURPOSE:[^\r\n]*\R[ \t]*\*\/\R?/m',
        '$1' . $newline,
        $contents
    ) ?? $contents;

    // Remove the earlier low-quality generated blocks before rebuilding them.
    // Hand-written comments do not contain these exact markers and remain intact.
    $contents = preg_replace(
        '/^[ \t]*\/\*\*\R[ \t]*\* METHOD PURPOSE:.*\R[ \t]*\* BEGINNER NOTE:.*\R[ \t]*\*\/\R?/m',
        '',
        $contents
    ) ?? $contents;

    // Merge a generated purpose block with a pre-existing one-line annotation
    // such as `/** @return array */`; adjacent PHPDoc blocks are confusing.
    $contents = preg_replace_callback(
        '/^([ \t]*)\/\*\*\s*(@[^\r\n]*?)\s*\*\/\R\1\/\*\*\R\1 \* FUNCTION PURPOSE: ([^\r\n]*)\R\1 \*\/\R?/m',
        static function (array $match) use ($newline): string {
            $indent = $match[1];

            return $indent . '/**' . $newline
                . $indent . ' * FUNCTION PURPOSE: ' . $match[3] . $newline
                . $indent . ' *' . $newline
                . $indent . ' * ' . $match[2] . $newline
                . $indent . ' */' . $newline;
        },
        $contents
    ) ?? $contents;

    $removedGenerated = $contents !== $beforeCleanup;
    $endsWithNewline = str_ends_with($contents, "\n") || str_ends_with($contents, "\r");
    $lines = preg_split('/\R/', $contents);
    if ($lines === false) {
        continue;
    }

    $output = [];
    $changed = $removedGenerated;
    foreach ($lines as $line) {
        if (preg_match('/^(\s*)(public|protected|private)?\s*(?:static\s+)?function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $line, $match) === 1) {
            $previous = count($output) - 1;
            while ($previous >= 0 && trim($output[$previous]) === '') {
                $previous--;
            }
            $alreadyExplained = false;
            $docStart = null;
            $previousText = $previous >= 0 ? trim($output[$previous]) : '';
            if (str_starts_with($previousText, '/**') && str_ends_with($previousText, '*/')) {
                // One-line prose is already a valid explanation. One-line
                // annotation-only PHPDoc is handled by the merge pass above.
                $alreadyExplained = !str_contains($previousText, '@');
            } elseif ($previous >= 0 && $previousText === '*/') {
                for ($scan = $previous; $scan >= 0; $scan--) {
                    if (trim($output[$scan]) === '/**') {
                        $docStart = $scan;
                        break;
                    }
                }

                if ($docStart !== null) {
                    // A docblock containing prose already explains the function.
                    // A block containing only @param/@return types needs purpose.
                    for ($scan = $docStart + 1; $scan < $previous; $scan++) {
                        $text = trim(ltrim(trim($output[$scan]), '*'));
                        if ($text !== '' && !str_starts_with($text, '@')) {
                            $alreadyExplained = true;
                            break;
                        }
                    }
                }
            } elseif ($previous >= 0 && str_starts_with(trim($output[$previous]), '//')) {
                $alreadyExplained = true;
            }

            if (!$alreadyExplained) {
                $indent = $match[1];
                $explanation = $indent . ' * FUNCTION PURPOSE: ' . $purpose($match[3]);
                if ($docStart !== null) {
                    // Merge the purpose into existing type documentation instead
                    // of creating two adjacent PHPDoc blocks.
                    array_splice($output, $docStart + 1, 0, [$explanation, $indent . ' *']);
                } else {
                    $output[] = $indent . '/**';
                    $output[] = $explanation;
                    $output[] = $indent . ' */';
                }
                $changed = true;
                $addedComments++;
            }
        }

        $output[] = $line;
    }

    if (!$changed) {
        continue;
    }

    // preg_split creates a final empty item for a trailing newline; avoid
    // accidentally adding a second one while reconstructing the file.
    if ($endsWithNewline && end($output) === '') {
        array_pop($output);
    }
    $rewritten = implode($newline, $output) . ($endsWithNewline ? $newline : '');
    if (file_put_contents($file->getPathname(), $rewritten) === false) {
        throw new RuntimeException('Could not update ' . $path);
    }
    $updatedFiles++;
}

echo "Added {$addedComments} function explanations across {$updatedFiles} files.\n";
