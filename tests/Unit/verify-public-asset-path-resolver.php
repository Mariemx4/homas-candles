<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap/autoload.php';

use App\Services\PublicAssetPathResolver;

$projectRoot = dirname(__DIR__, 2);
$resolver = new PublicAssetPathResolver(
    $projectRoot . '/public',
    $projectRoot . '/public/assets/brand'
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
