<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Command-line maintenance tool for Check Database.
 *
 * DATA FLOW: An operator runs this file from the project root to perform an explicit development or maintenance task outside an HTTP request.
 */

// WHAT: the fastest possible "is the database reachable?" check.
// WHY:  run this first when something's broken — before touching
//       migrations, seeds, or repositories — to rule out a bad
//       database.local.php or a MySQL service that isn't running.
require_once __DIR__ . '/../app/config/database.php';

try {
    // DATABASE SETUP: Open the real test/development connection used by this integration operation.
    $result = database()->query(
        'SELECT 1 AS connection_ok, DATABASE() AS database_name, '
        . '@@character_set_connection AS connection_charset'
    )->fetch();

    if ($result === false || (int) $result['connection_ok'] !== 1) {
        throw new RuntimeException('The development query returned an unexpected result.');
    }

    echo sprintf(
        "Database connection OK (database: %s, charset: %s).%s",
        $result['database_name'],
        $result['connection_charset'],
        PHP_EOL
    );
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    // RESULT STEP: Return a process status code; zero means success and non-zero lets automation detect failure.
    exit(1);
}
