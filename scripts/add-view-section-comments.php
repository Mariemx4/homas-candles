<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Place explanatory comments beside meaningful HTML sections and
 * forms in PHP templates, rather than leaving all explanation at file level.
 *
 * Labels come from the template's own heading/ARIA text, so comments stay tied
 * to the visible responsibility of each region. Existing section comments are
 * preserved, and repeated runs do not duplicate generated comments.
 */

$root = dirname(__DIR__);
$searchRoots = [$root . '/app/Views', $root . '/public'];
$updatedFiles = 0;
$addedComments = 0;

/** Convert class/id fragments into readable words. */
$readable = static function (string $value): string {
    $value = preg_replace('/^admin-/', '', $value) ?? $value;
    $value = str_replace(['--', '__', '-', '_'], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;

    return trim($value);
};

foreach ($searchRoots as $searchRoot) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($searchRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if ($contents === false || !str_contains($contents, '<')) {
            continue;
        }

        // Map heading IDs to their visible wording for comments such as
        // `aria-labelledby="urgent-actions-title"`.
        $headingById = [];
        preg_match_all(
            '/<h[1-6][^>]*id="([^"]+)"[^>]*>(.*?)<\/h[1-6]>/is',
            $contents,
            $headingMatches,
            PREG_SET_ORDER
        );
        foreach ($headingMatches as $heading) {
            $headingById[$heading[1]] = trim(html_entity_decode(strip_tags($heading[2])));
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
            if (preg_match('/^(\s*)<(section|form)\b([^>]*)>/i', $line, $match) === 1) {
                $indent = $match[1];
                $tag = strtolower($match[2]);
                $attributes = $match[3];
                $previous = count($output) - 1;
                while ($previous >= 0 && trim($output[$previous]) === '') {
                    $previous--;
                }
                $alreadyCommented = $previous >= 0
                    && str_contains($output[$previous], $tag === 'form' ? 'FORM PURPOSE:' : 'SECTION PURPOSE:');

                // A PHP closing-tag sequence inside an action attribute contains
                // a greater-than character, so inspect the complete source line.
                preg_match('/method="([^"]+)"/i', $line, $methodMatch);
                $method = strtoupper($methodMatch[1] ?? 'GET');

                if ($alreadyCommented && $tag === 'form') {
                    $corrected = preg_replace('/ with (?:GET|POST|PUT|PATCH|DELETE);/', ' with ' . $method . ';', $output[$previous])
                        ?? $output[$previous];
                    if ($corrected !== $output[$previous]) {
                        $output[$previous] = $corrected;
                        $changed = true;
                    }
                }

                if (!$alreadyCommented) {
                    $label = '';
                    if (preg_match('/aria-labelledby="([^"]+)"/i', $attributes, $labelMatch) === 1) {
                        $label = $headingById[$labelMatch[1]] ?? $readable($labelMatch[1]);
                    } elseif (preg_match('/aria-label="([^"]+)"/i', $attributes, $labelMatch) === 1) {
                        $label = $labelMatch[1];
                    } elseif (preg_match('/class="([^"]+)"/i', $attributes, $classMatch) === 1) {
                        // The first class usually names the component's responsibility.
                        $label = $readable(explode(' ', trim($classMatch[1]))[0]);
                    }
                    $label = $label !== '' ? $label : ($tag === 'form' ? 'submitted values' : 'related page content');

                    if ($tag === 'form') {
                        $comment = "FORM PURPOSE: Submit {$label} with {$method}; the receiving PHP route must validate the values before using them.";
                    } else {
                        $comment = "SECTION PURPOSE: Group the {$label} content, states, and controls as one accessible page region.";
                    }

                    $output[] = $indent . '<!-- ' . $comment . ' -->';
                    $changed = true;
                    $addedComments++;
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
        $rewritten = implode($newline, $output) . ($endsWithNewline ? $newline : '');
        if (file_put_contents($file->getPathname(), $rewritten) === false) {
            throw new RuntimeException('Could not update ' . $file->getPathname());
        }
        $updatedFiles++;
    }
}

echo "Added {$addedComments} inline view-section explanations across {$updatedFiles} files.\n";
