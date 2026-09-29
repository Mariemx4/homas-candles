<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Unit verification for Verify Public Asset Path Resolver.
 *
 * DATA FLOW: This executable test isolates a small behavior and fails with a non-zero result when its contract is broken.
 */

// SETUP STEP: Load the application code or delegated test required before this command can run.
require_once __DIR__ . '/../../app/Bootstrap/autoload.php';

use App\Services\PublicAssetPathResolver;

$resolver = new PublicAssetPathResolver(
    PUBLIC_PATH,
    PUBLIC_PATH . '/assets/brand'
);

$validPath = $resolver->resolve('assets/brand/logo.png');
if ($validPath !== 'assets/brand/logo.png') {
    throw new RuntimeException('Expected the in-bounds public asset to resolve.');
}

$privatePath = $resolver->resolve('../app/config/database.local.php');
if ($privatePath !== null) {
    throw new RuntimeException('A private path escaped the public asset boundary.');
}

echo '[PASS] Public asset paths remain inside their allowed directory.' . PHP_EOL;
