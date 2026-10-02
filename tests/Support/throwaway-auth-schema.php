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
            $db->exec((string) file_get_contents(APP_PATH . '/database/seed.sql'));
        }
    } catch (Throwable $exception) {
        drop_throwaway_auth_schema($db, $schema);
        throw $exception;
    }
    return [$db, $schema];
}

function drop_throwaway_auth_schema(PDO $db, string $schema): void
{
    if (preg_match('/^homas_auth_check_[a-f0-9]{16}$/', $schema) !== 1) {
        throw new RuntimeException('Refusing to drop a schema this helper did not create.');
    }
    $db->exec('SET search_path TO public');
    $db->exec('DROP SCHEMA IF EXISTS ' . $schema . ' CASCADE');
}
