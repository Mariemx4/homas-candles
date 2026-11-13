<?php

declare(strict_types=1);



// WHAT: checks the pure parts of App\Http\ErrorBoundary — status choice,
//       protected log line, and the rendered page — without sending headers.
// WHY:  the boundary is what visitors see during an outage and what the owner
//       reads afterwards; a leak (credentials, tokens, stack traces) or a
//       wrong status here would be invisible until the next real incident.
//       End to end: with the database unreachable, `php -S 127.0.0.1:8099 -t public`
//       and a request to product.php must answer 503 with Retry-After and a
//       reference that matches app/storage/logs/application.log.
require_once __DIR__ . '/../../app/Bootstrap/autoload.php';
require_once __DIR__ . '/../Support/TestCheck.php';

use App\Database\DatabaseUnavailableException;
use App\Http\ErrorBoundary;

$check = TestCheck::quiet();

// A realistic connection failure whose message contains the username and host.
$pdoFailure = new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'root'@'127.0.0.2' (using password: YES)");
$outage = new DatabaseUnavailableException(DatabaseUnavailableException::REASON_CONNECTION_FAILED, $pdoFailure);

// 1. Status: database outages are temporary (503); bugs are 500.
$check(ErrorBoundary::statusFor($outage) === 503, 'A database outage answers 503.');
$check(ErrorBoundary::statusFor(new PDOException('SQLSTATE[HY000] [2002] No connection')) === 503, 'A lost/unreachable server answers 503.');
$check(ErrorBoundary::statusFor(new RuntimeException('Wrapped', 0, $outage)) === 503, 'A wrapped outage is still recognised.');
$check(ErrorBoundary::statusFor(new PDOException('SQLSTATE[42S02]: Base table or view not found: 1146')) === 500, 'A broken query is a 500, not an outage.');
$check(ErrorBoundary::statusFor(new LogicException('bug')) === 500, 'Programming errors answer 500.');

// 2. Diagnostics come from the SQLSTATE prefix only.
$check($outage->diagnostics() === ['sqlstate' => 'HY000', 'driver_code' => 1045], 'SQLSTATE and driver code are extracted.');

// 3. The log line is useful but protected.
$server = [
    'REQUEST_METHOD' => 'GET',
    'REQUEST_URI' => '/homas-candles/public/reset-password.php?token=SECRET-TOKEN&slug=x',
];
$line = ErrorBoundary::logLine($outage, 503, 'abc123def456', 'product-page', $server);
foreach (['incident=abc123def456', 'status=503', 'context=product-page', 'method=GET',
    'path=/homas-candles/public/reset-password.php', 'reason=connection_failed', 'sqlstate=HY000', 'driver_code=1045',
    'error=App\\Database\\DatabaseUnavailableException'] as $expected) {
    $check(str_contains($line, $expected), "The log line includes {$expected}.");
}
foreach (['root', '127.0.0.2', 'Access denied', 'SECRET-TOKEN', 'token=', PROJECT_ROOT] as $forbidden) {
    $check(!str_contains($line, $forbidden), "The log line must not contain {$forbidden}.");
}
$check(!str_contains($line, "\n"), 'The log line is a single line.');

$bugLine = ErrorBoundary::logLine(new RuntimeException("Broken\n\"quoted\" value"), 500, 'x', 'uncaught', ['REQUEST_METHOD' => 'POST<script>']);
$check(
    preg_match('/message="Broken quoted\s+value"$/', $bugLine) === 1 && str_contains($bugLine, 'method=-'),
    'Application messages lose newlines and quotes, and hostile input is neutralised.'
);
$check(str_contains($bugLine, 'at=tests/Unit/verify-error-boundary.php:'), 'The location is relative to the project root.');

// 4. The page is controlled: a reference, a retry, direct channels — and no internals.
$page = ErrorBoundary::renderPage(503, 'abc123def456');
$check(str_contains($page, 'We’ll be back shortly.') && str_contains($page, 'abc123def456'), 'The outage page shows the incident reference.');
$check(str_contains($page, 'href="">Try again') && str_contains($page, 'wa.me'), 'The page offers a retry and a direct channel.');
foreach (['Access denied', 'PDOException', 'database.php', 'Stack trace'] as $forbidden) {
    $check(!str_contains($page, $forbidden), "The page must not reveal {$forbidden}.");
}
$check(str_contains(ErrorBoundary::renderPage(500, 'x'), 'Something went wrong.'), 'A 500 has its own wording.');

// RESULT STEP: Report success only after every assertion above has passed.
echo "Error boundary checks passed.\n";
