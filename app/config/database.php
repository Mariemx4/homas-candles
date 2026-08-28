<?php

declare(strict_types=1);

/**
 * Create the application's shared PDO connection.
 *
 * Credentials live in database.local.php, which is excluded from Git.
 */
// WHAT: the one function every page/repository calls to get a database
//       connection — nobody else in the app calls `new PDO(...)` directly.
// WHY:  centralizing this means credentials, error handling, and PDO
//       options only need to be right in one place.
function database(): PDO
{
    // WHAT: reuse the same connection across calls in one request.
    // WHY:  `static` keeps this variable alive between calls to database()
    //       within a single request, so opening the DB connection twice
    //       just returns the one already open instead of reconnecting.
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    // WHAT: load the gitignored file with the real host/user/password.
    // WHY:  never commit real credentials — database.example.php (tracked
    //       in git) is the template a new developer copies to make this file.
    $configFile = __DIR__ . '/database.local.php';

    if (!is_file($configFile)) {
        error_log('Database configuration is missing.');
        throw new RuntimeException('The database connection is not configured.');
    }

    $config = require $configFile;

    if (!is_array($config)) {
        error_log('Database configuration is invalid.');
        throw new RuntimeException('The database connection is not configured.');
    }

    // Accept the legacy local names so existing developer credentials keep working.
    $host = $config['host'] ?? null;
    $port = $config['port'] ?? 3306;
    $databaseName = $config['database'] ?? $config['db_name'] ?? null;
    $username = $config['username'] ?? $config['user_name'] ?? null;
    $password = $config['password'] ?? $config['psswd'] ?? null;
    $charset = $config['charset'] ?? 'utf8mb4';

    // WHAT: refuse to connect with a half-filled or malformed config.
    // WHY:  fail loudly here with one generic message (never echoing the
    //       actual host/user/password) instead of letting PDO fail later
    //       with a confusing, possibly credential-leaking error.
    if (!is_string($host) || $host === ''
        || (!is_int($port) && !ctype_digit((string) $port))
        || !is_string($databaseName) || $databaseName === ''
        || !is_string($username) || $username === ''
        || !is_string($password)
        || $charset !== 'utf8mb4'
    ) {
        error_log('Database configuration is incomplete.');
        throw new RuntimeException('The database connection is not configured.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $host,
        (int) $port,
        $databaseName
    );

    try {
        // WHAT: connect with strict/safe defaults.
        // WHY:  ERRMODE_EXCEPTION turns DB errors into catchable exceptions
        //       instead of silent failures; EMULATE_PREPARES=false makes
        //       MySQL do real server-side prepared statements, which is
        //       what actually protects every query in this app from SQL
        //       injection (see any repository's ->prepare()/->execute() calls).
        $connection = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException) {
        // Do not leak the DSN, username, or password to the response or logs.
        error_log('Database connection failed.');
        throw new RuntimeException('The database is temporarily unavailable.');
    }

    return $connection;
}
