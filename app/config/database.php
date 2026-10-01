<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Runtime configuration for Database.
 *
 * DATA FLOW: Bootstrap code reads this configuration before constructing application objects. Keep secrets environment-driven and keep request or rendering logic out of this layer.
 */

// Some CLI scripts load this file directly, so make sure the autoloader (and
// with it DatabaseUnavailableException and the path constants) is available.
require_once __DIR__ . '/../Bootstrap/autoload.php';

use App\Database\DatabaseUnavailableException;

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

    // WHAT: every failure below throws DatabaseUnavailableException.
    // WHY:  it extends RuntimeException (existing catch blocks keep working),
    //       and its reason() lets App\Http\ErrorBoundary answer with HTTP 503
    //       and log which stage failed — without any credential in the log.
    if (!is_file($configFile)) {
        error_log('Database unavailable: configuration file app/config/database.local.php is missing.');
        throw new DatabaseUnavailableException(DatabaseUnavailableException::REASON_MISSING_CONFIG);
    }

    $config = require $configFile;

    if (!is_array($config)) {
        error_log('Database unavailable: app/config/database.local.php must return an array.');
        throw new DatabaseUnavailableException(DatabaseUnavailableException::REASON_INVALID_CONFIG);
    }

    // The local verification command starts PHP's built-in server against a
    // randomly named throwaway database. Never allow this override under Apache
    // or for a database name outside the test helper's generated namespace.
    $verificationDatabase = getenv('HOMAS_VERIFICATION_DATABASE');
    if ($verificationDatabase !== false) {
        if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)
            || preg_match('/^homas_candles_verify_check_[a-f0-9]{8}$/', $verificationDatabase) !== 1) {
            throw new RuntimeException('Invalid verification database override.');
        }
        $config['database'] = $verificationDatabase;
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
        error_log('Database unavailable: app/config/database.local.php is missing a host, port, database, username, password, or utf8mb4 charset.');
        throw new DatabaseUnavailableException(DatabaseUnavailableException::REASON_INCOMPLETE_CONFIG);
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
            // Fail promptly if MariaDB opens its socket but cannot complete
            // the handshake; otherwise every database-backed page can wait
            // until PHP's full request timeout expires.
            PDO::ATTR_TIMEOUT => 5,
        ]);
    } catch (PDOException $exception) {
        // WHAT: log only the SQLSTATE and driver code (e.g. 2002 = server not
        //       reachable, 1045 = access denied, 1049 = unknown database).
        // WHY:  enough to diagnose the outage, while the PDO message — which
        //       can contain the host and username — is never written anywhere.
        $diagnostics = DatabaseUnavailableException::pdoDiagnostics($exception);
        error_log(sprintf(
            'Database unavailable: connection failed (SQLSTATE %s, driver code %s).',
            $diagnostics['sqlstate'] ?? 'unknown',
            $diagnostics['driver_code'] ?? 'unknown'
        ));
        throw new DatabaseUnavailableException(DatabaseUnavailableException::REASON_CONNECTION_FAILED, $exception);
    }

    return $connection;
}
