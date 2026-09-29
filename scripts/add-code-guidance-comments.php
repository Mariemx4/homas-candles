<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Add consistent educational headers to project-owned code files.
 *
 * WHY THIS EXISTS: The application spans HTTP entry points, bootstrap wiring,
 * controllers, services, repositories, views, browser code, and database files.
 * These headers explain the role of each layer at the point where it is read.
 * Running the script again is safe because files carrying the marker are skipped.
 */

$projectRoot = dirname(__DIR__);
$supportedExtensions = ['php', 'js', 'css', 'sql'];
$excludedParts = [
    DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR,
    DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR,
    DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR,
    DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR,
    DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR,
];

/** Convert a filename such as admin-product-edit into a readable topic. */
$topicFor = static function (string $path): string {
    $name = pathinfo($path, PATHINFO_FILENAME);
    $name = ltrim($name, '_');
    $name = preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $name) ?? $name;
    $name = str_replace(['-', '_'], ' ', $name);

    return ucwords(trim($name));
};

/**
 * Describe both the responsibility of a file and its place in the application flow.
 * More-specific directory rules intentionally come before broader ones.
 *
 * @return array{purpose: string, flow: string}
 */
$guidanceFor = static function (string $relativePath, string $topic): array {
    $path = str_replace('\\', '/', $relativePath);

    return match (true) {
        str_starts_with($path, 'app/Bootstrap/') => [
            'purpose' => "Composition root for {$topic}; it creates and connects this feature's repositories, services, and controllers.",
            'flow' => 'A public HTTP entry point requires this file first, then uses the objects exposed here. It should wire dependencies, not render HTML or contain business rules.',
        ],
        str_starts_with($path, 'app/Views/Layouts/') => [
            'purpose' => "Outer HTML layout for {$topic}; it provides the shared page shell and view helpers.",
            'flow' => 'The entry point prepares $contentView and page data, then requires this layout. Variables defined here, including $escape, are in scope when the layout requires the selected page view.',
        ],
        str_starts_with($path, 'app/Views/Components/') => [
            'purpose' => "Reusable view fragment for {$topic}.",
            'flow' => 'A parent view prepares the variables this fragment expects and requires it in the same PHP scope. This is a partial, not a page that should be opened directly.',
        ],
        str_starts_with($path, 'app/Views/Pages/') => [
            'purpose' => "Page view for {$topic}; it turns prepared application data into HTML.",
            'flow' => 'An HTTP entry point loads a bootstrap, obtains data from a controller, selects this file as $contentView, and loads a layout. Layout variables such as $escape are therefore injected into this view scope.',
        ],
        str_starts_with($path, 'app/config/') => [
            'purpose' => "Runtime configuration for {$topic}.",
            'flow' => 'Bootstrap code reads this configuration before constructing application objects. Keep secrets environment-driven and keep request or rendering logic out of this layer.',
        ],
        str_starts_with($path, 'app/database/') => [
            'purpose' => "Database definition or seed data for {$topic}.",
            'flow' => 'Migration and development scripts execute this SQL to create or populate persistent state; application repositories later read and update that state.',
        ],
        str_starts_with($path, 'app/helpers/') => [
            'purpose' => "Shared helper definitions for {$topic}.",
            'flow' => 'Bootstrap or entry-point code loads these small cross-cutting helpers so other layers can reuse one implementation.',
        ],
        str_starts_with($path, 'src/Controllers/') => [
            'purpose' => "Application controller for {$topic}; it coordinates a use case for the HTTP-facing layer.",
            'flow' => 'A public entry point calls this controller with request-ready input. The controller delegates rules to services and returns data for a redirect, response, or view.',
        ],
        str_starts_with($path, 'src/Services/') => [
            'purpose' => "Business service for {$topic}; it contains application rules and coordinates related persistence work.",
            'flow' => 'Controllers call this layer. It validates and normalizes domain input, calls repositories or storage collaborators, and returns structured results without rendering HTML.',
        ],
        str_starts_with($path, 'src/Repositories/') => [
            'purpose' => "Persistence repository for {$topic}; it owns the SQL used to read and write this feature's data.",
            'flow' => 'Services call this layer with validated values. The repository talks to PDO and returns database records or persistence results; it does not render views.',
        ],
        str_starts_with($path, 'src/Auth/') => [
            'purpose' => "Authentication/session component for {$topic}.",
            'flow' => 'Authentication entry points and guards use this layer to validate identities, manage session state, and pass authenticated account data into protected routes.',
        ],
        str_starts_with($path, 'src/Security/') => [
            'purpose' => "Security component for {$topic}.",
            'flow' => 'Forms and request handlers share this component to enforce a security boundary consistently before state-changing work is allowed.',
        ],
        str_starts_with($path, 'src/Database/') => [
            'purpose' => "Database infrastructure for {$topic}.",
            'flow' => 'Repositories and services use this low-level component to manage database behavior shared across multiple features.',
        ],
        str_starts_with($path, 'src/Mail/') => [
            'purpose' => "Mail boundary for {$topic}.",
            'flow' => 'Application services call this abstraction or implementation when a use case needs email, keeping delivery details out of controllers and views.',
        ],
        str_starts_with($path, 'public/admin/') => [
            'purpose' => "Administrator HTTP entry point for {$topic}.",
            'flow' => 'The web server invokes this file for a request. It loads bootstrap wiring, enforces administrator access, invokes a controller, and then redirects or selects a page view and layout.',
        ],
        str_starts_with($path, 'public/categories/') => [
            'purpose' => "Customer-facing category route for {$topic}.",
            'flow' => 'The web server invokes this compatibility/route file, which selects category input and delegates page construction to the shared catalogue flow.',
        ],
        str_starts_with($path, 'public/assets/js/') => [
            'purpose' => "Browser-side behavior for {$topic}.",
            'flow' => 'A rendered page loads this script after the HTML exists. It finds elements through documented selectors/data attributes and enhances interaction without owning server data.',
        ],
        str_starts_with($path, 'public/assets/css/') => [
            'purpose' => "Presentation rules for {$topic}.",
            'flow' => 'Layouts or page stylesheets load these rules in the browser. Selectors correspond to classes emitted by PHP views; changing either side may require updating the other.',
        ],
        str_starts_with($path, 'public/') => [
            'purpose' => "Customer-facing HTTP entry point for {$topic}.",
            'flow' => 'The web server invokes this file for a request. It loads shared bootstrap code, handles request input through application classes, and renders or redirects the response.',
        ],
        str_starts_with($path, 'tests/Unit/') => [
            'purpose' => "Unit verification for {$topic}.",
            'flow' => 'This executable test isolates a small behavior and fails with a non-zero result when its contract is broken.',
        ],
        str_starts_with($path, 'tests/Integration/') => [
            'purpose' => "Integration verification for {$topic}.",
            'flow' => 'This executable test crosses application and persistence boundaries to confirm the feature layers work together.',
        ],
        str_starts_with($path, 'scripts/verify-') => [
            'purpose' => "Command-line verification launcher for {$topic}.",
            'flow' => 'Run this wrapper from the project root; it delegates to the corresponding test while keeping a convenient scripts/ entry point.',
        ],
        str_starts_with($path, 'scripts/') => [
            'purpose' => "Command-line maintenance tool for {$topic}.",
            'flow' => 'An operator runs this file from the project root to perform an explicit development or maintenance task outside an HTTP request.',
        ],
        str_starts_with($path, 'src/') => [
            'purpose' => "Application class for {$topic}.",
            'flow' => 'The App\\ namespace autoloader maps this class to src/. Other application layers refer to its namespace instead of requiring this file directly.',
        ],
        default => [
            'purpose' => "Project code for {$topic}.",
            'flow' => 'This file is part of the application and is loaded by the neighboring entry point, build, or runtime flow.',
        ],
    };
};

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS)
);

$updated = [];
foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile()) {
        continue;
    }

    $absolutePath = $fileInfo->getPathname();
    $wrappedPath = DIRECTORY_SEPARATOR . ltrim($absolutePath, DIRECTORY_SEPARATOR);
    $isExcluded = false;
    foreach ($excludedParts as $part) {
        if (str_contains($wrappedPath, $part)) {
            $isExcluded = true;
            break;
        }
    }
    if ($isExcluded) {
        continue;
    }

    $extension = strtolower($fileInfo->getExtension());
    if (!in_array($extension, $supportedExtensions, true)) {
        continue;
    }

    $contents = file_get_contents($absolutePath);
    if ($contents === false || str_contains($contents, 'FILE PURPOSE:')) {
        continue;
    }

    $relativePath = substr($absolutePath, strlen($projectRoot) + 1);
    $topic = $topicFor($relativePath);
    $guidance = $guidanceFor($relativePath, $topic);
    $newline = str_contains($contents, "\r\n") ? "\r\n" : "\n";

    if ($extension === 'php') {
        if (!str_starts_with(ltrim($contents, "\xEF\xBB\xBF"), '<?php')) {
            continue;
        }

        $comment = '/**' . $newline
            . ' * FILE PURPOSE: ' . $guidance['purpose'] . $newline
            . ' *' . $newline
            . ' * DATA FLOW: ' . $guidance['flow'] . $newline
            . ' */';

        if (preg_match('/declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/', $contents, $match, PREG_OFFSET_CAPTURE) === 1) {
            $end = $match[0][1] + strlen($match[0][0]);
            $contents = substr($contents, 0, $end) . $newline . $newline . $comment . substr($contents, $end);
        } else {
            $openTagEnd = strpos($contents, '<?php') + strlen('<?php');
            $contents = substr($contents, 0, $openTagEnd) . $newline . $newline . $comment . substr($contents, $openTagEnd);
        }
    } elseif (in_array($extension, ['js', 'css'], true)) {
        $comment = '/*' . $newline
            . ' * FILE PURPOSE: ' . $guidance['purpose'] . $newline
            . ' * DATA FLOW: ' . $guidance['flow'] . $newline
            . ' */' . $newline . $newline;
        $contents = $comment . $contents;
    } else {
        $comment = '-- FILE PURPOSE: ' . $guidance['purpose'] . $newline
            . '-- DATA FLOW: ' . $guidance['flow'] . $newline . $newline;
        $contents = $comment . $contents;
    }

    if (file_put_contents($absolutePath, $contents) === false) {
        throw new RuntimeException("Unable to update {$relativePath}");
    }

    $updated[] = $relativePath;
}

echo 'Added guidance comments to ' . count($updated) . " files.\n";
