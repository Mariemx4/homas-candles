<?php

declare(strict_types=1);

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


    public function __construct(
        private readonly string $reason,
        ?Throwable $previous = null
    ) {
        // The message is shown in CLI scripts and caught by controllers, so it
        // must stay generic and identical to the one database() used before.
        parent::__construct('The database is temporarily unavailable.', 0, $previous);
    }


    public function reason(): string
    {
        return $this->reason;
    }


    public function diagnostics(): array
    {
        $previous = $this->getPrevious();

        return $previous instanceof PDOException
            ? self::pdoDiagnostics($previous)
            : ['sqlstate' => null, 'driver_code' => null];
    }


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
