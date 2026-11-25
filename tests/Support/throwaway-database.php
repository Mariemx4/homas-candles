<?php

declare(strict_types=1);
require_once __DIR__ . '/../../app/Bootstrap/autoload.php';

function throwaway_postgres_connection(?string $name = null): PDO
{
    $config = require APP_PATH . '/config/database.local.php';
    return new PDO(
        'pgsql:host=' . $config['host'] . ';port=' . ($config['port'] ?? 5432) . ';dbname=' . ($name ?? $config['database']) . ';connect_timeout=10',
        $config['username'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES => false]
    );
}

/** A separate PostgreSQL database, never the configured shop database. */
function create_throwaway_database(string $purpose, ?int $throughVersion = null): array
{
    if (!preg_match('/^[a-z]{3,20}$/D', $purpose)) {
        throw new InvalidArgumentException('Use a short lowercase verification purpose.');
    }
    $name = 'homas_candles_' . $purpose . '_check_' . bin2hex(random_bytes(4));
    $server = throwaway_postgres_connection();
    $server->exec('CREATE DATABASE "' . $name . '" TEMPLATE template0 ENCODING ' . $server->quote('UTF8'));
    $GLOBALS['_homas_created_test_databases'][$name] = true;
    try {
        $connection = throwaway_postgres_connection($name);
        $connection->exec("SET TIME ZONE 'UTC'; SET search_path TO public");
        $connection->exec("CREATE COLLATION homas_unicode_ci (provider=icu,locale='und-u-ks-level1',deterministic=false)");
        $connection->exec((string)file_get_contents(APP_PATH . '/database/schema_migrations.sql'));
        $migrations = glob(APP_PATH . '/database/migrations/*.sql') ?: [];
        sort($migrations, SORT_STRING);
        foreach ($migrations as $file) {
            $version = (int)substr(basename($file), 0, 3);
            if ($throughVersion !== null && $version > $throughVersion) {
                continue;
            }$connection->exec((string)file_get_contents($file));
            $statement = $connection->prepare('INSERT INTO schema_migrations(version,migration_name,file_checksum) VALUES(?,?,?)');
            $statement->execute([sprintf('%03d', $version),basename($file),hash_file('sha256', $file)]);
        }
        if ($throughVersion === null) {
            $connection->exec((string)file_get_contents(APP_PATH . '/database/reference.sql'));
        }
        return [$connection,$name];
    } catch (Throwable $error) {
        $server->exec('DROP DATABASE "' . $name . '" WITH (FORCE)');
        unset($GLOBALS['_homas_created_test_databases'][$name]);
        throw $error;
    }
}

function drop_throwaway_database(PDO $connection, string $name): void
{
    if (!preg_match('/^homas_candles_[a-z]{3,20}_check_[a-f0-9]{8}$/D', $name) || empty($GLOBALS['_homas_created_test_databases'][$name])) {
        throw new RuntimeException('Refusing to remove a database this process did not create.');
    }
    throwaway_postgres_connection()->exec('DROP DATABASE "' . $name . '" WITH (FORCE)');
    unset($GLOBALS['_homas_created_test_databases'][$name]);
}
