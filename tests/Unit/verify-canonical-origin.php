<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap/autoload.php';
require_once __DIR__ . '/../Support/TestCheck.php';

use App\Http\CanonicalOrigin;

$count = 0;
$check = TestCheck::printing($count);
$callback = 'http://localhost/auth/google-callback.php';
$request = ['HTTP_HOST' => 'homas-candles.test', 'REQUEST_URI' => '/admin/login.php?session_expired=1', 'REQUEST_METHOD' => 'GET'];
$redirect = CanonicalOrigin::redirect($callback, $request);
$check($redirect === ['url' => 'http://localhost/admin/login.php?session_expired=1', 'status' => 302], 'Alternate-host login moves to the configured origin before cookies or CSRF are issued.');
$check(CanonicalOrigin::redirect($callback, array_replace($request, ['REQUEST_METHOD' => 'POST']))['status'] === 307, 'POST canonicalization preserves the method and body.');
$check(CanonicalOrigin::redirect($callback, array_replace($request, ['REQUEST_METHOD' => 'HEAD']))['status'] === 302, 'HEAD uses a temporary safe-method redirect.');
$check(CanonicalOrigin::redirect($callback, ['HTTP_HOST' => 'localhost', 'REQUEST_URI' => '/login.php']) === null, 'Canonical localhost never loops.');
$check(CanonicalOrigin::redirect($callback, ['HTTP_HOST' => 'LOCALHOST:80']) === null, 'Case and default ports denote the same origin.');
$check(CanonicalOrigin::redirect('http://localhost:8787/auth/google-callback.php', ['HTTP_HOST' => 'localhost:8787']) === null, 'Explicit preview ports work without redirects.');
$check(CanonicalOrigin::redirect('https://shop.test/auth/google-callback.php', ['HTTP_HOST' => 'shop.test', 'HTTPS' => 'on']) === null, 'A canonical HTTPS deployment never loops.');
$check(CanonicalOrigin::redirect($callback, ['HTTP_HOST' => 'attacker.test', 'REQUEST_URI' => '//attacker.test/path'])['url'] === 'http://localhost//attacker.test/path', 'Untrusted hosts and path-like authorities cannot select a redirect origin.');
$check(CanonicalOrigin::redirect($callback, ['HTTP_HOST' => 'attacker.test', 'REQUEST_URI' => "/login.php\r\nInjected: yes"])['url'] === 'http://localhost/', 'Malformed request paths cannot inject response headers.');
$check(CanonicalOrigin::redirect('javascript:alert(1)', $request) === null, 'Non-HTTP configured destinations are rejected.');
$check(CanonicalOrigin::redirect('http://user:secret@localhost/auth/google-callback.php', $request) === null, 'Configured userinfo is never copied into a redirect.');
echo 'Canonical origin verification: ' . $count . ' checks passed.' . PHP_EOL;
