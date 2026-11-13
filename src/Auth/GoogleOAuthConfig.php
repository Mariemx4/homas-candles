<?php

declare(strict_types=1);

namespace App\Auth;

/** Explicit callbacks avoid Host-header redirects and accidental secret disclosure. */
final class GoogleOAuthConfig
{
    public readonly string $clientId;
    public readonly string $redirectUri;
    private readonly string $secret;

    public function __construct(#[\SensitiveParameter] array $values, private readonly string $basePath = '/')
    {
        $this->clientId = is_string($values['client_id'] ?? null) ? trim($values['client_id']) : '';
        $this->secret = is_string($values['client_secret'] ?? null) ? trim($values['client_secret']) : '';
        $this->redirectUri = is_string($values['redirect_uri'] ?? null) ? trim($values['redirect_uri']) : '';
    }

    public function configured(): bool
    {
        if (preg_match('/^[A-Za-z0-9_-]+\.apps\.googleusercontent\.com$/D', $this->clientId) !== 1
            || $this->secret === '' || strlen($this->secret) > 512 || preg_match('/\s/', $this->secret)) {
            return false;
        }
        $url = parse_url($this->redirectUri);
        if (preg_match('/[\x00-\x20\x7f]/', $this->redirectUri) || !is_array($url) || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment']) || !isset($url['host'], $url['scheme'])) {
            return false;
        }
        $localHttp = $url['scheme'] === 'http' && in_array($url['host'], ['localhost', '127.0.0.1', '[::1]'], true);
        return ($url['scheme'] === 'https' || $localHttp)
            && ($url['path'] ?? '') === rtrim($this->basePath, '/') . '/auth/google-callback.php';
    }

    public function available(): bool
    {
        return $this->configured() && class_exists(\Firebase\JWT\JWT::class);
    }

    public function clientSecret(): string
    {
        return $this->secret;
    }

    public function __debugInfo(): array
    {
        return ['configured' => $this->configured(), 'redirect_uri' => $this->redirectUri];
    }
}
