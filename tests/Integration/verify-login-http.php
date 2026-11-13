<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/throwaway-auth-schema.php';
require_once __DIR__ . '/../Support/TestCheck.php';
require_once __DIR__ . '/../Support/TestPhpServer.php';

// A separate server and schema exercise real forms/cookies without using shop accounts.
[$db, $schema] = create_throwaway_auth_schema(true);
$server = null;
$clients = [];
$sessionDirectory = sys_get_temp_dir() . '/' . $schema . '_sessions';
$serverLog = sys_get_temp_dir() . '/' . $schema . '.log';
$check = TestCheck::printing();

try {
    mkdir($sessionDirectory, 0700);
    $password = 'VerifyLogin-' . bin2hex(random_bytes(8));
    foreach (['customers_accounts', 'administrators_accounts'] as $table) {
        $db->prepare("INSERT INTO {$table} (first_name, last_name, email, phone_number, password_hash)
            VALUES ('HTTP', 'Login', :email, '99112233', :hash)")
            ->execute(['email' => $table . '@example.test', 'hash' => password_hash($password, PASSWORD_BCRYPT)]);
    }
    $address = TestPhpServer::reserveAddress();
    $server = TestPhpServer::start($address, $sessionDirectory, $serverLog, [
        'PGOPTIONS' => '-c search_path=' . $schema,
        'APP_BASE_URL' => '/',
        'LOGIN_THROTTLE_KEY' => bin2hex(random_bytes(32)),
        'HOMAS_VERIFICATION_DATABASE' => null,
    ]);
    $request = static function (CurlHandle $client, string $path, ?array $post = null) use ($address): array {
        $location = '';
        curl_setopt_array($client, [
            CURLOPT_URL => 'http://' . $address . '/' . $path,
            CURLOPT_POSTFIELDS => $post === null ? null : http_build_query($post),
            CURLOPT_POST => $post !== null,
            CURLOPT_HTTPGET => $post === null,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$location): int {
                if (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, 9));
                }
                return strlen($header);
            },
        ]);
        $body = curl_exec($client);
        if (!is_string($body)) {
            throw new RuntimeException('HTTP test request failed.');
        }
        return [(int) curl_getinfo($client, CURLINFO_HTTP_CODE), $body, $location];
    };
    $token = static function (string $html): string {
        if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $match) !== 1) {
            throw new RuntimeException('Login CSRF field is missing.');
        }
        return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
    };
    foreach (
        [
            ['customers_accounts', 'login.php', 'index.php', 'logout.php'],
            ['administrators_accounts', 'admin/login.php', 'admin/index.php', 'admin/logout.php'],
        ] as [$table, $login, $destination, $logout]
    ) {
        $client = curl_init();
        $clients[] = $client;
        curl_setopt_array($client, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 10]);
        [$status, $form] = $request($client, $login);
        $check($status === 200, $login . ' renders successfully.');
        [$status,, $location] = $request($client, $login, [
            'csrf_token' => $token($form),
            'login_identifier' => $table . '@example.test',
            'password' => 'WrongPassword1',
        ]);
        [$formStatus, $form] = $request($client, $login);
        $check($status === 303 && $location === '/' . $login && $formStatus === 200
            && str_contains($form, 'Unable to sign in.'), $login . ' reports incorrect credentials safely.');
        [$status,, $location] = $request($client, $login, [
            'csrf_token' => 'invalid',
            'login_identifier' => $table . '@example.test',
            'password' => $password,
        ]);
        [$formStatus, $form] = $request($client, $login);
        $check(
            $status === 303 && $location === '/' . $login && str_contains($form, 'Your form expired.'),
            $login . ' rejects an invalid CSRF token.'
        );
        [$status,, $location] = $request($client, $login, [
            'csrf_token' => $token($form),
            'login_identifier' => $table . '@example.test',
            'password' => $password,
        ]);
        $check($status === 303 && $location === '/' . $destination, $login . ' redirects after successful sign-in.');
        [$status, $page] = $request($client, $destination);
        $check($status === 200 && str_contains($page, '/' . $logout), $destination . ' retains the authenticated session.');
        [$status,, $location] = $request($client, $logout, ['csrf_token' => $token($page)]);
        $check($status === 303 && $location === '/' . $login . '?signed_out=1', $logout . ' signs out successfully.');
    }
} finally {
    $server?->stop();
    foreach ($clients as $client) {
        curl_close($client);
    }
    drop_throwaway_auth_schema($db, $schema);
    foreach (glob($sessionDirectory . '/*') ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($sessionDirectory)) {
        rmdir($sessionDirectory);
    }
    if (is_file($serverLog)) {
        unlink($serverLog);
    }
}
