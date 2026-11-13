<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/config/database.php';

/** Verify PostgreSQL authentication in an isolated schema, never against shop accounts. */
function create_throwaway_auth_schema(bool $fullApplication = false): array
{
    $db = database();
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
        throw new RuntimeException('Authentication schema verification requires PostgreSQL.');
    }
    $schema = 'homas_auth_check_' . bin2hex(random_bytes(8));
    $db->exec('CREATE SCHEMA ' . $schema);
    try {
        $db->exec('SET search_path TO ' . $schema);
        $db->exec("CREATE COLLATION homas_unicode_ci
            (provider = icu, locale = 'und-u-ks-level1', deterministic = false)");
        $migrations = $fullApplication ? (glob(APP_PATH . '/database/migrations/*.sql') ?: []) : array_map(
            static fn (string $name): string => APP_PATH . '/database/migrations/' . $name,
            [
                '002_create_administrators_accounts_table.sql',
                '003_create_customers_accounts_table.sql',
                '004_create_customer_addresses_table.sql',
                '020_create_login_throttle.sql',
            ]
        );
        sort($migrations, SORT_STRING);
        foreach ($migrations as $migration) {
            $db->exec((string) file_get_contents($migration));
        }
        if ($fullApplication) {
            $db->exec((string) file_get_contents(APP_PATH . '/database/reference.sql'));
        }
    } catch (Throwable $exception) {
        drop_throwaway_auth_schema($db, $schema);
        throw $exception;
    }
    return [$db, $schema];
}

/**
 * Move the shared application connection into a new, fully migrated schema.
 *
 * Call this immediately after requiring a bootstrap and BEFORE the suite reads
 * or writes anything. database() returns one shared PDO per PHP process, so the
 * services the bootstrap already constructed follow the new search_path too.
 *
 * The schema is dropped by a shutdown handler, so it is removed even when an
 * assertion throws outside a try block or a fatal error ends the script.
 * Suites may still drop it themselves; dropping twice is harmless.
 *
 * @return array{0: PDO, 1: string} The shared connection and the generated schema name.
 */
function isolate_application_database(): array
{
    [$db, $schema] = create_throwaway_auth_schema(true);
    register_shutdown_function(static function () use ($db, $schema): void {
        drop_throwaway_auth_schema($db, $schema);
    });
    require_throwaway_schema($db);

    return [$db, $schema];
}

/**
 * Refuse to continue unless the connection points at a generated test schema.
 *
 * This is the safety net for suites that write data: if isolation was skipped
 * or undone, the suite stops before touching the real shop tables.
 */
function require_throwaway_schema(PDO $db): void
{
    $current = (string) $db->query('SELECT current_schema()')->fetchColumn();
    if (preg_match('/^homas_auth_check_[a-f0-9]{16}$/D', $current) !== 1) {
        throw new RuntimeException('Refusing to run: the connection is not on a generated test schema (current: ' . $current . ').');
    }
}

function drop_throwaway_auth_schema(PDO $db, string $schema): void
{
    if (preg_match('/^homas_auth_check_[a-f0-9]{16}$/', $schema) !== 1) {
        throw new RuntimeException('Refusing to drop a schema this helper did not create.');
    }
    $db->exec('SET search_path TO public');
    $db->exec('DROP SCHEMA IF EXISTS ' . $schema . ' CASCADE');
}
