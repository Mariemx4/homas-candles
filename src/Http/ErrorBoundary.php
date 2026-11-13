<?php

declare(strict_types=1);

namespace App\Http;

use App\Database\DatabaseUnavailableException;
use PDOException;
use Throwable;

// WHAT: turns an escaped exception into (1) one diagnosable log line and
//       (2) a friendly page with HTTP 503 (try again soon) or 500 (our bug).
// WHY:  bootstrap files open the database before any controller try/catch
//       runs, so a database outage used to escape as a bare PHP 500 — and
//       with display_errors=On (XAMPP default) that response could even show
//       file paths and exception text. A 503 with Retry-After tells browsers,
//       crawlers and monitors that the outage is temporary.
//
//       "Protected" logging means: useful for the owner (incident reference,
//       route, error type, SQLSTATE/driver code, file:line), but never the
//       query string (it can hold reset tokens), never a PDO message (it can
//       hold the host and username), and never written under the web root.
final class ErrorBoundary
{
    public const LOG_FILE = 'application.log';
    public const RETRY_AFTER_SECONDS = 120;

    // MySQL/MariaDB driver codes meaning "the server is down or overloaded",
    // not "the query is wrong": can't connect (2002/2003), connection lost
    // (2006/2013), too many connections (1040), lock wait timeout (1205).
    private const TRANSIENT_DRIVER_CODES = [1040, 1205, 2002, 2003, 2006, 2013];

    private static bool $responding = false;


    public static function register(): void
    {
        set_exception_handler(static function (Throwable $exception): void {
            self::respond($exception, 'uncaught');
        });
    }


    public static function respond(Throwable $exception, string $context): never
    {
        // A failure while rendering the error page must not loop back here.
        if (self::$responding) {
            exit(1);
        }
        self::$responding = true;

        $status = self::statusFor($exception);
        $incident = bin2hex(random_bytes(6));
        self::writeLog(self::logLine($exception, $status, $incident, $context, $_SERVER));

        if (headers_sent()) {
            // Part of the page is already on its way; the status can no longer
            // change, so close with a short notice instead of a second document.
            echo '<p role="alert">Something went wrong while loading this page. Reference: '
                . htmlspecialchars($incident, ENT_QUOTES, 'UTF-8') . '</p>';
            exit(1);
        }

        // Discard any half-rendered output so the visitor gets one clean page.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        if ($status === 503) {
            header('Retry-After: ' . self::RETRY_AFTER_SECONDS);
        }
        echo self::renderPage($status, $incident);
        exit(1);
    }


    public static function statusFor(Throwable $exception): int
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof DatabaseUnavailableException) {
                return 503;
            }
            if ($current instanceof PDOException) {
                $code = DatabaseUnavailableException::pdoDiagnostics($current)['driver_code'];
                if ($code !== null && in_array($code, self::TRANSIENT_DRIVER_CODES, true)) {
                    return 503;
                }
            }
        }

        return 500;
    }


    public static function logLine(Throwable $exception, int $status, string $incident, string $context, array $server): string
    {
        $method = is_string($server['REQUEST_METHOD'] ?? null) && preg_match('/^[A-Z]{1,10}$/', $server['REQUEST_METHOD']) === 1
            ? $server['REQUEST_METHOD'] : '-';
        // Path only: the query string is dropped because it can carry tokens.
        $path = is_string($server['REQUEST_URI'] ?? null) ? (string) parse_url($server['REQUEST_URI'], PHP_URL_PATH) : '-';

        $fields = [
            'incident' => $incident,
            'status' => (string) $status,
            'context' => self::clean($context, 40),
            'method' => $method,
            'path' => self::clean($path === '' ? '-' : $path, 200),
            'error' => get_class($exception),
            'at' => self::relativeLocation($exception),
        ];

        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof DatabaseUnavailableException) {
                $fields['reason'] = $current->reason();
            }
            if ($current instanceof PDOException) {
                // Codes only — a PDO message can include host and username.
                $diagnostics = DatabaseUnavailableException::pdoDiagnostics($current);
                $fields['sqlstate'] = $diagnostics['sqlstate'] ?? 'unknown';
                $fields['driver_code'] = (string) ($diagnostics['driver_code'] ?? 'unknown');
            }
        }
        $hasPdo = isset($fields['sqlstate']);
        if (!$hasPdo && !$exception instanceof DatabaseUnavailableException) {
            // Application messages are ours and help debugging; keep them short and on one line.
            $fields['message'] = '"' . self::clean($exception->getMessage(), 300) . '"';
        }

        $pairs = [];
        foreach ($fields as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        return '[' . date('c') . '] ' . implode(' ', $pairs);
    }


    public static function renderPage(int $status, string $incident): string
    {
        if (!defined('BASE_URL')) {
            require_once APP_PATH . '/config/config.php';
        }
        try {
            $contact = require APP_PATH . '/config/contact.php';
        } catch (Throwable) {
            $contact = null;
        }

        ob_start();
        try {
            (static function (int $status, string $incident, ?array $contact): void {
                require APP_PATH . '/Views/Pages/service-unavailable.php';
            })($status, $incident, is_array($contact) ? $contact : null);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }


    private static function writeLog(string $line): void
    {
        $directory = defined('LOG_PATH') ? LOG_PATH : null;
        if ($directory !== null && (is_dir($directory) || @mkdir($directory, 0750, true))
            && @error_log($line . PHP_EOL, 3, $directory . '/' . self::LOG_FILE)
        ) {
            return;
        }
        error_log($line);
    }


    private static function relativeLocation(Throwable $exception): string
    {
        $file = str_replace('\\', '/', $exception->getFile());
        $root = defined('PROJECT_ROOT') ? str_replace('\\', '/', PROJECT_ROOT) . '/' : '';
        if ($root !== '' && str_starts_with($file, $root)) {
            $file = substr($file, strlen($root));
        }

        return self::clean($file, 200) . ':' . $exception->getLine();
    }


    private static function clean(string $value, int $maximum): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F"]+/u', ' ', $value) ?? '';
        $value = trim($value);

        return function_exists('mb_substr') ? mb_substr($value, 0, $maximum) : substr($value, 0, $maximum);
    }
}
