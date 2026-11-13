<?php
declare(strict_types=1);

// Development-only formatting. Applied SQL migrations and private configuration
// are deliberately excluded: their checksums/credentials are not source cleanup.
$finder = PhpCsFixer\Finder::create()
    ->in(['src', 'app/Bootstrap', 'app/Views', 'app/config', 'public', 'scripts', 'tests'])
    ->name('*.php')
    ->notName('*.local.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setUsingCache(false)
    ->setRules([
        '@PSR12' => true,
        'concat_space' => ['spacing' => 'one'],
        'no_unused_imports' => true,
    ])
    ->setFinder($finder);
