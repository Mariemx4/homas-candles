<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/config/database.php';

// WHAT: applies every *.sql file in app/database/migrations/ that hasn't
//       run yet, in order, and records what it ran.
// WHY:  this is the script you run after adding/editing a migration file —
//       see README.md for the exact command. It refuses to silently re-run
//       or skip a migration that was edited after being applied (see the
//       checksum check below), so a schema file never drifts from what's
//       actually in the database without you finding out.
function migration_runner(): void
{
    $connection = database();
    $migrationDirectory = __DIR__ . '/../app/database/migrations';
    $bootstrapFile = __DIR__ . '/../app/database/schema_migrations.sql';

    if (!is_dir($migrationDirectory)) {
        throw new RuntimeException(
            "Migration directory not found: {$migrationDirectory}"
        );
    }

    if (!is_file($bootstrapFile)) {
        throw new RuntimeException(
            "Bootstrap migration not found: {$bootstrapFile}"
        );
    }

    /*
     * The tracking table must exist before we can discover which
     * migrations have already been applied.
     */
    $bootstrapSql = file_get_contents($bootstrapFile);

    if ($bootstrapSql === false || trim($bootstrapSql) === '') {
        throw new RuntimeException(
            "Bootstrap migration is empty or unreadable: {$bootstrapFile}"
        );
    }

    $connection->exec($bootstrapSql);

    // WHAT: every migration file, sorted by filename (so 001 runs before 002).
    $migrationFiles = glob($migrationDirectory . '/*.sql');

    if ($migrationFiles === false) {
        throw new RuntimeException('The migration files could not be read.');
    }

    sort($migrationFiles, SORT_STRING);

    // WHAT: what's already been applied, from the schema_migrations table.
    $appliedStatement = $connection->query(
        'SELECT version, file_checksum FROM schema_migrations'
    );

    $appliedMigrations = [];

    foreach ($appliedStatement->fetchAll() as $migration) {
        $appliedMigrations[$migration['version']] =
            $migration['file_checksum'];
    }

    $recordStatement = $connection->prepare(
        'INSERT INTO schema_migrations
            (version, migration_name, file_checksum)
         VALUES
            (:version, :migration_name, :file_checksum)'
    );

    foreach ($migrationFiles as $migrationFile) {
        $filename = basename($migrationFile);

        // WHAT: filenames must look like "001_something.sql".
        // WHY:  the leading number becomes the migration's `version` and
        //       also decides run order — a file that doesn't match this
        //       shape is almost certainly a typo, so fail instead of
        //       guessing.
        if (!preg_match('/^(\d{3,})_[a-z0-9_]+\.sql$/', $filename, $matches)) {
            throw new RuntimeException(
                "Invalid migration filename: {$filename}"
            );
        }

        $version = $matches[1];
        $checksum = hash_file('sha256', $migrationFile);

        if ($checksum === false) {
            throw new RuntimeException(
                "Could not calculate checksum for: {$filename}"
            );
        }

        // WHAT: if this version was already applied, make sure the file on
        //       disk still matches what actually ran.
        // WHY:  stops a silent bug where you edit an already-applied
        //       migration file (e.g. to "fix" it) and assume the database
        //       picked up the change — it didn't, and this throws instead
        //       of pretending everything's fine.
        if (isset($appliedMigrations[$version])) {
            if (!hash_equals($appliedMigrations[$version], $checksum)) {
                throw new RuntimeException(
                    "Applied migration was modified: {$filename}"
                );
            }

            echo "[SKIPPED] {$filename}" . PHP_EOL;
            continue;
        }

        $sql = file_get_contents($migrationFile);

        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException(
                "Migration is empty or unreadable: {$filename}"
            );
        }

        $connection->exec($sql);

        $recordStatement->execute([
            'version' => $version,
            'migration_name' => $filename,
            'file_checksum' => $checksum,
        ]);

        echo "[APPLIED] {$filename}" . PHP_EOL;
    }

    echo 'Migration run completed.' . PHP_EOL;
}

try {
    migration_runner();
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'Migration failed: ' . $exception->getMessage() . PHP_EOL
    );

    exit(1);
}
