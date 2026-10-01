<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Shared test support for Throwaway Database; builds and removes a disposable MySQL/MariaDB database.
 *
 * DATA FLOW: Integration tests call create_throwaway_database() to get a PDO
 * connected to a brand-new database containing every migration and seed.sql
 * by default. An optional version limit builds an unseeded upgrade fixture,
 * and drop_throwaway_database() in a finally block. The developer's real
 * homas_candles data is never read or written.
 */

require_once __DIR__ . '/../../app/Bootstrap/autoload.php';

/**
 * FUNCTION PURPOSE: Create a disposable database with the full schema and reference data.
 *
 * WHAT: uses the server credentials from database.local.php but a new,
 *       randomly named database.
 * WHY:  tests of CHECK constraints, triggers and locking SQL need the real
 *       engine, but must never touch development data.
 *
 * @param int|null $throughVersion Apply only migrations through this version; zero creates an empty schema.
 * @return array{0: PDO, 1: string} The connection and the database name to drop later.
 */
function create_throwaway_database(string $purpose, ?int $throughVersion = null): array
{
    if (preg_match('/^[a-z]{3,20}$/', $purpose) !== 1) {
        throw new InvalidArgumentException('Use a short lowercase purpose for the throwaway database name.');
    }
    $name = 'homas_candles_' . $purpose . '_check_' . bin2hex(random_bytes(4));
    // The name is interpolated into SQL (identifiers cannot be bound), so it
    // must be exactly the safe generated shape.
    if (preg_match('/^homas_candles_[a-z]{3,20}_check_[a-f0-9]{8}$/', $name) !== 1) {
        throw new RuntimeException('Unsafe temporary database name.');
    }

    $config = require APP_PATH . '/config/database.local.php';
    // Same PDO options as database() in app/config/database.php.
    $connection = new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config['host'] ?? '127.0.0.1', (int) ($config['port'] ?? 3306)),
        $config['username'] ?? $config['user_name'] ?? '',
        $config['password'] ?? $config['psswd'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $connection->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    try {
        $connection->exec("USE `{$name}`");
        $connection->exec((string) file_get_contents(APP_PATH . '/database/schema_migrations.sql'));
        $migrations = glob(APP_PATH . '/database/migrations/*.sql') ?: [];
        sort($migrations, SORT_STRING);
        foreach ($migrations as $migration) {
            $version = (int) substr(basename($migration), 0, 3);
            if ($throughVersion !== null && $version > $throughVersion) {
                continue;
            }
            $connection->exec((string) file_get_contents($migration));
            if ($throughVersion !== null) {
                $statement = $connection->prepare('INSERT INTO schema_migrations (version, migration_name, file_checksum) VALUES (?, ?, ?)');
                $statement->execute([sprintf('%03d', $version), basename($migration), hash_file('sha256', $migration)]);
            }
        }
        if ($throughVersion === null) {
            $connection->exec((string) file_get_contents(APP_PATH . '/database/seed.sql'));
        }
    } catch (Throwable $exception) {
        // A half-built database is useless; remove it before reporting the failure.
        $connection->exec("DROP DATABASE IF EXISTS `{$name}`");
        throw $exception;
    }

    return [$connection, $name];
}

/**
 * FUNCTION PURPOSE: Remove a database created by create_throwaway_database().
 */
function drop_throwaway_database(PDO $connection, string $name): void
{
    if (preg_match('/^homas_candles_[a-z]{3,20}_check_[a-f0-9]{8}$/', $name) !== 1) {
        throw new RuntimeException('Refusing to drop a database this helper did not create.');
    }
    $connection->exec("DROP DATABASE IF EXISTS `{$name}`");
}
