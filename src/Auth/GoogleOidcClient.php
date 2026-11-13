<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\JsonHttpClient;
use DomainException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Throwable;

/** Authorization-code exchange plus signed ID-token verification; no browser claims are trusted. */
final class GoogleOidcClient
{
    public const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const KEYS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    public function __construct(
        private readonly GoogleOAuthConfig $config,
        private readonly JsonHttpClient $http,
        private readonly string $cacheFile
    ) {
    }

    public function authorizationUrl(array $flow): string
    {
        if (!$this->config->available()) {
            throw new DomainException('Google sign-in is unavailable.');
        }
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $flow['verifier'], true)), '+/', '-_'), '=');
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'client_id' => $this->config->clientId, 'redirect_uri' => $this->config->redirectUri,
            'response_type' => 'code', 'scope' => 'openid email profile', 'access_type' => 'online',
            'prompt' => 'select_account', 'state' => $flow['state'], 'nonce' => $flow['nonce'],
            'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange(#[\SensitiveParameter] string $code, array $flow): GoogleIdentity
    {
        if (!$this->config->available() || $code === '' || strlen($code) > 4096) {
            throw new DomainException('Invalid Google sign-in request.');
        }
        $response = $this->http->request('POST', self::TOKEN_URL, [
            'grant_type' => 'authorization_code', 'code' => $code,
            'client_id' => $this->config->clientId, 'client_secret' => $this->config->clientSecret(),
            'redirect_uri' => $this->config->redirectUri, 'code_verifier' => $flow['verifier'],
        ]);
        $token = $response['body']['id_token'] ?? null;
        if (!is_string($token) || strlen($token) > 16384) {
            throw new DomainException('Google did not return an identity token.');
        }
        try {
            $segments = explode('.', $token);
            $header = json_decode(JWT::urlsafeB64Decode($segments[0]), true, 8, JSON_THROW_ON_ERROR);
            if (count($segments) !== 3 || !is_array($header) || ($header['alg'] ?? null) !== 'RS256'
                || !is_string($header['kid'] ?? null) || strlen($header['kid']) > 255) {
                throw new DomainException('Unexpected signing algorithm.');
            }
            // The header chooses a key, never an algorithm or a network location.
            $keys = $this->keys();
            if (!array_filter($keys['keys'], static fn ($key) => ($key['kid'] ?? null) === $header['kid'])) {
                $keys = $this->keys(true);
            }
            $claims = (array) JWT::decode($token, JWK::parseKeySet($keys, 'RS256'));
        } catch (Throwable) {
            throw new DomainException('Google identity verification failed.');
        }
        $audience = $claims['aud'] ?? null;
        $audienceMatches = $audience === $this->config->clientId
            || (is_array($audience) && in_array($this->config->clientId, $audience, true)
                && ($claims['azp'] ?? null) === $this->config->clientId);
        if (!in_array($claims['iss'] ?? null, ['https://accounts.google.com', 'accounts.google.com'], true)
            || !$audienceMatches || (isset($claims['azp']) && $claims['azp'] !== $this->config->clientId)
            || !is_int($claims['exp'] ?? null) || $claims['exp'] <= time()
            || !is_int($claims['iat'] ?? null) || $claims['iat'] > time() + 60
            || !is_string($claims['nonce'] ?? null) || !hash_equals($flow['nonce'], $claims['nonce'])
            || ($claims['email_verified'] ?? false) !== true
            || !is_string($claims['sub'] ?? null) || !is_string($claims['email'] ?? null)
            || (array_key_exists('hd', $claims) && !is_string($claims['hd']))) {
            throw new DomainException('Google identity verification failed.');
        }
        return new GoogleIdentity(
            $claims['sub'],
            strtolower($claims['email']),
            is_string($claims['given_name'] ?? null) ? $claims['given_name'] : '',
            is_string($claims['family_name'] ?? null) ? $claims['family_name'] : '',
            $claims['hd'] ?? null
        );
    }

    private function keys(bool $refresh = false): array
    {
        $cached = is_file($this->cacheFile) ? json_decode((string) file_get_contents($this->cacheFile), true) : null;
        if (!$refresh && is_array($cached) && ($cached['expires_at'] ?? 0) > time() && is_array($cached['keys'] ?? null)) {
            return ['keys' => $cached['keys']];
        }
        $response = $this->http->request('GET', self::KEYS_URL);
        $keys = array_values(array_filter($response['body']['keys'] ?? [], static fn ($key) => is_array($key)
            && ($key['kty'] ?? null) === 'RSA' && ($key['alg'] ?? 'RS256') === 'RS256'
            && ($key['use'] ?? 'sig') === 'sig'));
        if ($keys === []) {
            throw new DomainException('Google signing keys are unavailable.');
        }
        $directory = dirname($this->cacheFile);
        if (is_dir($directory) || @mkdir($directory, 0700, true)) {
            $data = json_encode(['expires_at' => time() + max(0, min(3600, $response['max_age'] ?? 300)), 'keys' => $keys], JSON_THROW_ON_ERROR);
            @file_put_contents($this->cacheFile, $data, LOCK_EX);
            @chmod($this->cacheFile, 0600);
        }
        return ['keys' => $keys];
    }
}
