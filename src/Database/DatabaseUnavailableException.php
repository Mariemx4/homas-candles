<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Database infrastructure for Database Unavailable Exception.
 *
 * DATA FLOW: database() in app/config/database.php throws this when no
 * connection can be opened. App\Http\ErrorBoundary turns it into an HTTP 503
 * page; controllers that already catch RuntimeException keep working because
 * this class extends it.
 */

namespace App\Database;

use PDOException;
use RuntimeException;
use Throwable;

// WHAT: "the database cannot be reached right now", as its own type.
// WHY:  a generic RuntimeException looked the same as a programming bug, so
//       the HTTP layer could not tell "try again in a minute" (503) from
//       "something is broken" (500). The public message stays generic; the
//       diagnostic details below are safe to log because they never contain
//       the DSN, host, username, or password.
final class DatabaseUnavailableException extends RuntimeException
{
    public const REASON_MISSING_CONFIG = 'missing_config';
    public const REASON_INVALID_CONFIG = 'invalid_config';
    public const REASON_INCOMPLETE_CONFIG = 'incomplete_config';
    public const REASON_CONNECTION_FAILED = 'connection_failed';

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly string $reason,
        ?Throwable $previous = null
    ) {
        // The message is shown in CLI scripts and caught by controllers, so it
        // must stay generic and identical to the one database() used before.
        parent::__construct('The database is temporarily unavailable.', 0, $previous);
    }

    /**
     * FUNCTION PURPOSE: Return which stage failed (configuration or connection).
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * FUNCTION PURPOSE: Return log-safe diagnostics from the underlying PDO error.
     *
     * WHAT: only the SQLSTATE and the numeric driver code (for example
     *       HY000 / 2002 "cannot connect" or 1045 "access denied").
     * WHY:  PDOException messages can include the host and username
     *       ("Access denied for user 'root'@'localhost'"), so the message
     *       itself is never logged.
     *
     * @return array{sqlstate: string|null, driver_code: int|null}
     */
    public function diagnostics(): array
    {
        $previous = $this->getPrevious();

        return $previous instanceof PDOException
            ? self::pdoDiagnostics($previous)
            : ['sqlstate' => null, 'driver_code' => null];
    }

    /**
     * FUNCTION PURPOSE: Extract the SQLSTATE and driver code from any PDOException without its message.
     *
     * @return array{sqlstate: string|null, driver_code: int|null}
     */
    public static function pdoDiagnostics(PDOException $exception): array
    {
        $info = $exception->errorInfo;
        $sqlState = is_array($info) && is_string($info[0] ?? null) ? $info[0] : null;
        $driverCode = is_array($info) && is_int($info[1] ?? null) ? $info[1] : null;

        // Connection failures often leave errorInfo empty; the message then
        // starts with "SQLSTATE[HY000] [2002]". Parse only those two tokens.
        if (($sqlState === null || $driverCode === null)
            && preg_match('/^SQLSTATE\[([0-9A-Z]{5})\](?: \[(\d+)\])?/', $exception->getMessage(), $matches) === 1
        ) {
            $sqlState ??= $matches[1];
            $driverCode ??= isset($matches[2]) ? (int) $matches[2] : null;
        }

        return ['sqlstate' => $sqlState, 'driver_code' => $driverCode];
    }
}
