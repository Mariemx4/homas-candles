<?php

declare(strict_types=1);



// WHAT: guards the single path definition in app/config/paths.php.
// WHY:  moving bootstrap files into app/Bootstrap/admin/ once made
//       dirname(__DIR__, N) point at the wrong folder, so product images and
//       payment evidence were read from and written to the wrong places.
//       This test fails if the constants drift, if private storage becomes
//       web-reachable, or if application code starts climbing folders again.
require_once __DIR__ . '/../../app/Bootstrap/autoload.php';
require_once __DIR__ . '/../Support/TestCheck.php';

use App\Services\PaymentEvidenceStorage;
use App\Services\ProductImageStorage;
use App\Services\PublicAssetPathResolver;

$check = TestCheck::quiet();
// Compare folders case-insensitively on Windows, with one trailing separator.
$normalize = static fn (string $path): string => (DIRECTORY_SEPARATOR === '\\' ? 'strtolower' : 'strval')(
    rtrim(str_replace('\\', '/', (string) realpath($path)), '/') . '/'
);
$inside = static fn (string $parent, string $child): bool => str_starts_with($normalize($child), $normalize($parent));

// 1. Every constant points at a real folder of the expected project.
foreach (['PROJECT_ROOT', 'APP_PATH', 'SOURCE_PATH', 'SCRIPTS_PATH', 'PUBLIC_PATH', 'PRODUCT_IMAGE_PATH',
    'STORAGE_PATH', 'PAYMENT_EVIDENCE_PATH', 'BACKUP_PATH', 'TEST_SESSION_PATH', 'LOG_PATH'] as $constant) {
    $check(defined($constant) && is_dir(constant($constant)), "{$constant} must point at an existing folder.");
}
$check(is_file(PROJECT_ROOT . '/composer.json') && is_file(PUBLIC_PATH . '/index.php'), 'PROJECT_ROOT is the repository root.');
$check(!is_dir(APP_PATH . '/public'), 'No stray app/public folder exists (the symptom of a wrong root).');

// 2. Public uploads live under the web root; private storage never does.
$check($inside(PUBLIC_PATH, PRODUCT_IMAGE_PATH), 'Product photos are served from the public web root.');
foreach (['STORAGE_PATH', 'PAYMENT_EVIDENCE_PATH', 'BACKUP_PATH', 'TEST_SESSION_PATH', 'LOG_PATH'] as $constant) {
    $check(!$inside(PUBLIC_PATH, constant($constant)), "{$constant} must stay outside the public web root.");
    $check($inside(APP_PATH, constant($constant)), "{$constant} must stay inside app/.");
}

// 3. The storage services find files where the constants say they are.
$planted = bin2hex(random_bytes(8)) . '.jpg';
$productFile = PRODUCT_IMAGE_PATH . DIRECTORY_SEPARATOR . $planted;
$evidenceFile = PAYMENT_EVIDENCE_PATH . DIRECTORY_SEPARATOR . $planted;
file_put_contents($productFile, 'path check');
file_put_contents($evidenceFile, 'path check');
try {
    $check((new ProductImageStorage(PRODUCT_IMAGE_PATH))->resolve('assets/products/' . $planted) === realpath($productFile), 'Admin product storage resolves public/assets/products.');
    $check((new PublicAssetPathResolver(PUBLIC_PATH, PRODUCT_IMAGE_PATH))->resolve('assets/products/' . $planted) === 'assets/products/' . $planted, 'The storefront resolves the same product photo.');
    $check((new PaymentEvidenceStorage(PAYMENT_EVIDENCE_PATH))->resolve($planted) === realpath($evidenceFile), 'Payment evidence resolves in private storage.');
} finally {
    @unlink($productFile);
    @unlink($evidenceFile);
}

// 4. Regression guard: runtime code must not rebuild project paths by climbing folders.
//    Loading sibling code with require __DIR__ . '/../x.php' is fine; building a
//    public/ or storage/ location from __DIR__, or any dirname(__DIR__, N), is not.
$offenders = [];
foreach ([APP_PATH . '/Bootstrap', APP_PATH . '/Views', SOURCE_PATH] as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        foreach (file($file->getPathname()) as $number => $line) {
            if (preg_match('#dirname\(__DIR__|__DIR__\s*\.\s*[\'"][^\'"]*(?:public|storage)/#', $line) === 1) {
                $offenders[] = str_replace('\\', '/', substr($file->getPathname(), strlen(PROJECT_ROOT) + 1)) . ':' . ($number + 1);
            }
        }
    }
}
$check($offenders === [], "Use the constants from app/config/paths.php instead of climbing folders:\n  " . implode("\n  ", $offenders));

// RESULT STEP: Report success only after every assertion above has passed.
echo "Project path checks passed.\n";
