<?php

declare(strict_types=1);

namespace App\Maintenance;

use PDO;
use RuntimeException;
use Throwable;

/** Private PostgreSQL archives; restore checks can create only disposable databases. */
final class PostgresBackup
{
    public function __construct(private readonly array $config, private readonly string $directory)
    {
    }

    private function databaseName(): string
    {
        $name = $this->config['database'] ?? '';
        if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_]+$/D', $name)) {
            throw new RuntimeException('Invalid backup database configuration.');
        }
        return $name;
    }

    private function connection(string $name): PDO
    {
        return new PDO(
            'pgsql:host=' . $this->config['host'] . ';port=' . ($this->config['port'] ?? 5432) . ';dbname=' . $name . ';connect_timeout=10',
            $this->config['username'],
            $this->config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }

    private function run(string $tool, string $database, array $arguments): void
    {
        $binary = getenv($tool === 'pg_dump' ? 'PG_DUMP_BINARY' : 'PG_RESTORE_BINARY') ?: $tool;
        $command = [
            $binary,
            '--host=' . $this->config['host'],
            '--port=' . ($this->config['port'] ?? 5432),
            '--username=' . ($this->config['username']),
            '--no-password',
            '--dbname=' . $database,
            ...$arguments
        ];
        $environment = getenv();
        $environment['PGPASSWORD'] = $this->config['password'];
        $environment['PGCONNECT_TIMEOUT'] = '10';
        $environment['PGOPTIONS'] = '-c timezone=UTC';
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start ' . $tool . '. Install the PostgreSQL client tools.');
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            error_log($tool . ' failed (exit ' . $status . ').');
            throw new RuntimeException($tool . ' failed. Check client/server compatibility, connection and permissions.');
        }
    }

    private function storage(): string
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true)) {
            throw new RuntimeException('Could not create private backup storage.');
        }
        $path = realpath($this->directory);
        $public = realpath(PUBLIC_PATH);
        if ($path === false || $public === false || str_starts_with(strtolower(str_replace('\\', '/', $path)) . '/', strtolower(str_replace('\\', '/', $public)) . '/')) {
            throw new RuntimeException('Backups must be outside the public web root.');
        }
        if (function_exists('posix_geteuid') && posix_geteuid() === 0 && ($group = posix_getgrnam('www-data')) !== false) {
            chgrp($path, $group['gid']);
            chmod($path, 0750);
        }
        return $path;
    }

    private function protectFile(string $path): void
    {
        chmod($path, 0600);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0 && ($group = posix_getgrnam('www-data')) !== false) {
            chgrp($path, $group['gid']);
            chmod($path, 0640);
        }
    }

    /** Hash rows in a stable order without writing their contents into metadata. */
    public function fingerprint(PDO $db): array
    {
        $tables = $db->query("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
        $result = [];
        foreach ($tables as $table) {
            $quoted = '"' . str_replace('"', '""', $table) . '"';
            $rows = $db->query('SELECT to_jsonb(t)::text AS body FROM public.' . $quoted . ' t ORDER BY to_jsonb(t)::text');
            $hash = hash_init('sha256');
            $count = 0;
            while ($row = $rows->fetch()) {
                hash_update($hash, $row['body'] . "\n");
                ++$count;
            }
            $result[$table] = ['rows' => $count, 'sha256' => hash_final($hash)];
        }
        return $result;
    }

    public function create(): string
    {
        $name = $this->databaseName();
        $directory = $this->storage();
        $archive = $directory . '/' . $name . '_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.dump';
        $partial = $archive . '.partial';
        $db = $this->connection($name);
        $db->beginTransaction();
        try {
            $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $snapshot = (string)$db->query('SELECT pg_export_snapshot()')->fetchColumn();
            $fingerprint = $this->fingerprint($db);
            $this->run('pg_dump', $name, ['--format=custom', '--no-owner', '--no-privileges', '--schema=public', '--snapshot=' . $snapshot, '--file=' . $partial]);
            if (!is_file($partial) || filesize($partial) === 0 || !rename($partial, $archive)) {
                throw new RuntimeException('The database archive could not be finalized.');
            }
            $this->protectFile($archive);
            $metadata = ['database' => $name, 'created_at' => gmdate('c'), 'sha256' => hash_file('sha256', $archive), 'tables' => $fingerprint];
            if (file_put_contents($archive . '.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) {
                throw new RuntimeException('Could not write archive verification metadata.');
            }
            $this->protectFile($archive . '.json');
            $db->commit();
            return $archive;
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if (is_file($partial)) {
                unlink($partial);
            }
            throw $error;
        }
    }

    public function checkRestore(string $requested): array
    {
        $directory = $this->storage();
        $archive = realpath($requested);
        if ($archive === false || dirname($archive) !== $directory || pathinfo($archive, PATHINFO_EXTENSION) !== 'dump') {
            throw new RuntimeException('Choose an archive in private backup storage.');
        }
        $metadata = json_decode((string)file_get_contents($archive . '.json'), true, 512, JSON_THROW_ON_ERROR);
        $hash = hash_file('sha256', $archive);
        if (!is_array($metadata) || !hash_equals($metadata['sha256'] ?? '', $hash)) {
            throw new RuntimeException('The archive checksum does not match.');
        }
        $temporary = 'homas_candles_restore_check_' . bin2hex(random_bytes(8));
        $admin = $this->connection($this->databaseName());
        $created = false;
        $restored = null;
        try {
            $admin->exec('CREATE DATABASE "' . $temporary . '" TEMPLATE template0 ENCODING \'UTF8\'');
            $created = true;
            $this->run('pg_restore', $temporary, ['--clean', '--if-exists', '--no-owner', '--no-privileges', '--exit-on-error', '--single-transaction', $archive]);
            $restored = $this->connection($temporary);
            if ($this->fingerprint($restored) !== $metadata['tables']) {
                throw new RuntimeException('Restored table contents differ from the exported snapshot.');
            }
            $receipt = ['archive' => basename($archive), 'sha256' => $hash, 'verified_at' => gmdate('c'), 'tables_verified' => count($metadata['tables'])];
            if (file_put_contents($archive . '.restore.json', json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) {
                throw new RuntimeException('Could not record the restore check.');
            }
            $this->protectFile($archive . '.restore.json');
            return $receipt;
        } finally {
            $restored = null;
            if ($created && preg_match('/^homas_candles_restore_check_[a-f0-9]{16}$/D', $temporary)) {
                $admin->exec('DROP DATABASE "' . $temporary . '"');
            }
        }
    }

    public static function latestVerified(string $directory): ?array
    {
        $files = glob($directory . '/*.dump.restore.json') ?: [];
        rsort($files, SORT_STRING);
        foreach ($files as $file) {
            if (!is_readable($file)) {
                continue;
            }
            $receipt = json_decode((string)file_get_contents($file), true);
            $archive = substr($file, 0, -strlen('.restore.json'));
            if (is_array($receipt) && is_readable($archive) && hash_equals((string)($receipt['sha256'] ?? ''), hash_file('sha256', $archive))) {
                return $receipt;
            }
        }
        return null;
    }
}
