<?php

declare(strict_types=1);



// WHAT: applies app/database/reference.sql (the production-safe reference data
//       only — see that file's own comment) to whichever database
//       database.local.php currently points at.
// WHY:  separate script from migrate.php because seeding is a data
//       operation, not a schema change — you might re-run this without
//       ever touching migrations, or vice versa.
require_once __DIR__ . '/../app/config/database.php';

$seedFile = __DIR__ . '/../app/database/reference.sql';
$seedSql = file_get_contents($seedFile);

if ($seedSql === false || trim($seedSql) === '') {
    throw new RuntimeException('The reference seed is empty or unreadable.');
}

database()->exec($seedSql);
echo 'Reference seed applied.' . PHP_EOL;
