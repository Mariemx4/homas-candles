<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap/autoload.php';
require_once __DIR__ . '/../Support/TestCheck.php';

use App\Auth\{GoogleOAuthConfig, GoogleOAuthSession, GoogleOidcClient};
use App\Http\{CurlJsonHttpClient, JsonHttpClient};
use Firebase\JWT\JWT;

/** In-memory provider responses; no credentials, external requests or database are used. */
final class GoogleFixtureHttpClient implements JsonHttpClient
{
    public string $token = '';
    public array $keys = [];
    public array $lastForm = [];
    public int $keyRequests = 0;

    public function request(string $method, string $url, #[SensitiveParameter] array $form = []): array
    {
        if ($method === 'POST' && $url === GoogleOidcClient::TOKEN_URL) {
            $this->lastForm = $form;
            return ['body' => ['id_token' => $this->token], 'max_age' => null];
        }
        if ($method === 'GET' && $url === GoogleOidcClient::KEYS_URL) {
            ++$this->keyRequests;
            return ['body' => ['keys' => $this->keys], 'max_age' => 300];
        }
        throw new RuntimeException('Unexpected fixture request.');
    }
}

$count = 0;
$check = TestCheck::printing($count);
$reject = static function (callable $operation, string $label) use ($check): void {
    try {
        $operation();
    } catch (DomainException | InvalidArgumentException | RuntimeException $error) {
        $check(true, $label);
        return;
    }
    $check(false, $label);
};
$directory = sys_get_temp_dir() . '/homas-google-unit-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$cache = $directory . '/keys.json';
$configValues = ['client_id' => 'fixture-client.apps.googleusercontent.com', 'client_secret' => 'fixture-secret-only',
    'redirect_uri' => 'http://localhost/auth/google-callback.php'];
$config = new GoogleOAuthConfig($configValues);
$http = new GoogleFixtureHttpClient();
$client = new GoogleOidcClient($config, $http, $cache);
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($key, $privateKey);
$details = openssl_pkey_get_details($key);
$base64 = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
$jwk = ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'fixture-key',
    'n' => $base64($details['rsa']['n']), 'e' => $base64($details['rsa']['e'])];
$http->keys = [$jwk];
$_SESSION = [];
$sessions = new GoogleOAuthSession();

try {
    $check($config->available(), 'Google sign-in requires valid configuration and an installed verifier.');
    $check(!(new GoogleOAuthConfig([]))->available(), 'Missing credentials disable Google sign-in.');
    $check(!(new GoogleOAuthConfig(array_replace($configValues, ['redirect_uri' => 'http://example.test/auth/google-callback.php'])))->configured(), 'Production callbacks must use HTTPS.');
    $check(!(new GoogleOAuthConfig(array_replace($configValues, ['redirect_uri' => 'http://localhost/auth/google-callback.php?next=elsewhere'])))->configured(), 'Callback queries cannot become redirect destinations.');
    $check(!(new GoogleOAuthConfig(array_replace($configValues, ['redirect_uri' => 'https://example.test/wrong.php'])))->configured(), 'The configured callback must match the application route.');
    $check((new GoogleOAuthConfig(array_replace($configValues, ['redirect_uri' => 'https://example.test/shop/auth/google-callback.php']), '/shop/'))->configured(), 'Subdirectory installations support an explicit matching callback.');
    $flow = $sessions->begin('signup.php');
    parse_str((string) parse_url($client->authorizationUrl($flow), PHP_URL_QUERY), $authorization);
    $check($authorization['state'] === $flow['state'] && $authorization['nonce'] === $flow['nonce'], 'Each authorization carries the server-issued state and nonce.');
    $check($authorization['code_challenge_method'] === 'S256' && $authorization['code_challenge'] === $base64(hash('sha256', $flow['verifier'], true)), 'Authorization uses PKCE with a SHA-256 challenge.');
    $check(!str_contains($client->authorizationUrl($flow), $configValues['client_secret']) && !str_contains($client->authorizationUrl($flow), $flow['verifier']), 'Neither the client secret nor PKCE verifier appears in the browser URL.');
    $check($sessions->consume($flow['state'])['origin'] === 'signup.php', 'A valid challenge is consumed once.');
    $reject(static fn () => $sessions->consume($flow['state']), 'Replaying an authorization state is rejected.');
    $reject(static fn () => $sessions->consume(['not-a-string']), 'Malformed callback state is rejected safely.');
    $adminFlow = $sessions->begin('admin/login.php', 'administrator');
    $check($sessions->isAdministratorFlow($adminFlow['state']) && $sessions->consume($adminFlow['state'], 'administrator')['origin'] === 'admin/login.php', 'Administrator state is bound to the admin callback and origin.');
    $adminFlow = $sessions->begin('admin/login.php', 'administrator');
    $reject(static fn () => $sessions->consume($adminFlow['state']), 'Customer callbacks cannot consume an administrator flow.');
    $reject(static fn () => $sessions->consume($adminFlow['state'], 'administrator'), 'A rejected cross-purpose state cannot subsequently be replayed.');
    $customerFlow = $sessions->begin('login.php');
    $check(!$sessions->isAdministratorFlow($customerFlow['state']) && !$sessions->isAdministratorFlow(['invalid']), 'Customer state and malformed input never select the administrator callback.');
    $reject(static fn () => $sessions->consume($customerFlow['state'], 'administrator'), 'Administrator callbacks cannot consume a customer flow.');
    $reject(static fn () => $sessions->begin('login.php', 'unrecognized'), 'Only server-defined authentication purposes can create state.');
    $lostAdmin = $sessions->begin('admin/login.php', 'administrator');
    unset($_SESSION['google_oauth_flows'][$lostAdmin['state']]);
    $check($sessions->isAdministratorFlow($lostAdmin['state']), 'Lost administrator state still selects the staff error page.');
    $reject(static fn () => $sessions->consume($lostAdmin['state'], 'administrator'), 'An administrator routing prefix alone never authorizes sign-in.');
    $expired = $sessions->begin('login.php');
    $_SESSION['google_oauth_flows'][$expired['state']]['expires_at'] = time() - 1;
    $reject(static fn () => $sessions->consume($expired['state']), 'Expired authorization challenges cannot authenticate.');

    $claims = ['iss' => 'https://accounts.google.com', 'aud' => $config->clientId, 'sub' => 'fixture-subject',
        'email' => 'customer@example.test', 'email_verified' => true, 'given_name' => 'Leila', 'family_name' => 'Haddad',
        'nonce' => $flow['nonce'], 'iat' => time() - 5, 'exp' => time() + 600];
    $http->token = JWT::encode($claims, $privateKey, 'RS256', 'fixture-key');
    $identity = $client->exchange('fixture-code', $flow);
    $check($identity->subject === 'fixture-subject' && $identity->email === 'customer@example.test', 'A signed Google identity with matching claims is accepted.');
    $check($http->lastForm['code_verifier'] === $flow['verifier'] && $http->lastForm['redirect_uri'] === $config->redirectUri, 'The token exchange binds the code to its verifier and exact callback.');
    $check($http->keyRequests === 1 && is_file($cache), 'Google public keys are cached according to their response lifetime.');
    $client->exchange('fixture-code', $flow);
    $check($http->keyRequests === 1, 'A live key cache avoids unnecessary provider requests.');
    $check(!$identity->ownsEmailAddress(), 'Verified third-party email without a hosted-domain claim still requires local proof.');
    $http->token = JWT::encode(array_replace($claims, ['email' => 'existing@gmail.com']), $privateKey, 'RS256', 'fixture-key');
    $check($client->exchange('fixture-code', $flow)->ownsEmailAddress(), 'A fully verified Gmail token proves current ownership for an existing account.');
    $http->token = JWT::encode(array_replace($claims, ['email' => 'existing@company.test', 'hd' => 'company.test']), $privateKey, 'RS256', 'fixture-key');
    $workspace = $client->exchange('fixture-code', $flow);
    $check($workspace->ownsEmailAddress() && $workspace->hostedDomain === 'company.test', 'Workspace ownership comes from the signed hd claim, not an email-domain guess.');
    $sessions->remember($workspace);
    $check($sessions->pending()['identity']->hostedDomain === 'company.test', 'Pending signup preserves the verified hosted-domain claim across completion.');
    $http->token = JWT::encode(array_replace($claims, ['email' => 'existing@gmail.com.attacker.test']), $privateKey, 'RS256', 'fixture-key');
    $check(!$client->exchange('fixture-code', $flow)->ownsEmailAddress(), 'A Gmail lookalike suffix never grants ownership of an existing account.');
    $http->token = JWT::encode(array_replace($claims, ['email' => 'existing@gmail.com', 'email_verified' => false]), $privateKey, 'RS256', 'fixture-key');
    $reject(static fn () => $client->exchange('fixture-code', $flow), 'An unverified Gmail claim cannot bypass verification.');
    foreach ([
        ['nonce', 'other-nonce', 'An identity from another authorization is rejected.'],
        ['aud', 'other-client.apps.googleusercontent.com', 'Tokens issued to a different client are rejected.'],
        ['iss', 'https://attacker.example.test', 'Tokens from another issuer are rejected.'],
        ['email_verified', false, 'Unverified email claims cannot register or link accounts.'],
        ['email_verified', 'true', 'Malformed email-verification claims are rejected.'],
        ['exp', time() - 60, 'Expired identity tokens are rejected.'],
        ['iat', time() + 600, 'Future-issued tokens are rejected.'],
        ['azp', 'other-client.apps.googleusercontent.com', 'A mismatched authorized party is rejected.'],
        ['sub', '', 'Empty provider subjects are rejected.'],
        ['email', 'not-an-email', 'Invalid verified-email values are rejected.'],
        ['hd', ['company.test'], 'An array cannot masquerade as a signed Workspace domain.'],
        ['hd', '', 'An empty hosted-domain claim cannot establish ownership.'],
        ['hd', 'https://company.test', 'A URL cannot masquerade as a hosted-domain claim.'],
    ] as [$field, $value, $label]) {
        $http->token = JWT::encode(array_replace($claims, [$field => $value]), $privateKey, 'RS256', 'fixture-key');
        $reject(static fn () => $client->exchange('fixture-code', $flow), $label);
    }
    $http->token = JWT::encode($claims, str_repeat('test-only-secret', 4), 'HS256', 'fixture-key');
    $reject(static fn () => $client->exchange('fixture-code', $flow), 'Symmetric algorithms cannot substitute for Google RSA signatures.');
    $http->token = 'not.a.valid-token';
    $reject(static fn () => $client->exchange('fixture-code', $flow), 'Malformed identity tokens are rejected.');
    $otherKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($otherKey, $otherPrivate);
    $http->token = JWT::encode($claims, $otherPrivate, 'RS256', 'fixture-key');
    $reject(static fn () => $client->exchange('fixture-code', $flow), 'A valid-looking token signed by another RSA key is rejected.');
    $withoutExpiry = $claims;
    unset($withoutExpiry['exp']);
    $http->token = JWT::encode($withoutExpiry, $privateKey, 'RS256', 'fixture-key');
    $reject(static fn () => $client->exchange('fixture-code', $flow), 'Identity tokens must declare an expiration time.');
    $http->token = JWT::encode($claims, $privateKey, 'RS256', 'rotated-key');
    $http->keys = [array_replace($jwk, ['kid' => 'rotated-key'])];
    $check($client->exchange('fixture-code', $flow)->subject === 'fixture-subject' && $http->keyRequests === 2, 'A newly rotated signing-key ID refreshes the cache before verification.');
    $sessions->remember($identity);
    $check(!isset($_SESSION['customer_auth']) && $sessions->pending()['identity']->subject === $identity->subject, 'A verified pending signup is not an authenticated customer session.');
    $check(array_keys($_SESSION['google_oauth_pending']['identity']) === ['subject', 'email', 'first_name', 'last_name', 'hosted_domain'], 'Pending signup stores only bounded identity fields, never tokens or credentials.');
    $sessions->remember($identity, 12);
    $check($sessions->needsLinkFor(12) && !$sessions->needsLinkFor(13), 'Existing-account linking is bound to the exact customer.');
    $_SESSION['google_oauth_pending']['expires_at'] = time() - 1;
    $check($sessions->pending() === null && !isset($_SESSION['google_oauth_pending']), 'Expired pending registrations are cleared.');
    $reject(static fn () => (new CurlJsonHttpClient())->request('POST', 'http://example.test/token', ['secret' => 'fixture']), 'The production transport refuses plaintext HTTP.');
    echo 'Google protocol verification: ' . $count . ' checks passed.' . PHP_EOL;
} finally {
    if (is_file($cache)) {
        unlink($cache);
    }
    rmdir($directory);
}
