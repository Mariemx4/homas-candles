<?php

declare(strict_types=1);

namespace App\Http;

/** Keep session cookies and OAuth state on the explicitly configured callback origin. */
final class CanonicalOrigin
{
    /** @return array{url:string,status:int}|null */
    public static function redirect(string $configuredCallback, array $server): ?array
    {
        $url = parse_url($configuredCallback);
        if (!is_array($url) || !in_array($url['scheme'] ?? '', ['http', 'https'], true)
            || !isset($url['host']) || isset($url['user']) || isset($url['pass'])
            || preg_match('/[\x00-\x20\x7f]/', $configuredCallback)) {
            return null;
        }
        $scheme = $url['scheme'];
        $authority = strtolower($url['host']) . (isset($url['port']) ? ':' . $url['port'] : '');
        $https = isset($server['HTTPS']) && $server['HTTPS'] !== '' && strtolower((string) $server['HTTPS']) !== 'off';
        $requestScheme = $https ? 'https' : 'http';
        $requestHost = strtolower((string) ($server['HTTP_HOST'] ?? ''));
        // Default ports denote the same origin. Host is compared, never used as
        // the redirect destination; forwarding headers do not authorize a host.
        $defaultPort = $scheme === 'https' ? ':443' : ':80';
        $canonicalHost = str_ends_with($authority, $defaultPort) ? substr($authority, 0, -strlen($defaultPort)) : $authority;
        $requestHost = str_ends_with($requestHost, $defaultPort) ? substr($requestHost, 0, -strlen($defaultPort)) : $requestHost;
        if ($requestScheme === $scheme && $requestHost === $canonicalHost) {
            return null;
        }
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        if (!str_starts_with($uri, '/') || preg_match('/[\x00-\x20\x7f]/', $uri)) {
            $uri = '/';
        }
        return ['url' => $scheme . '://' . $authority . $uri,
            'status' => in_array($server['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) ? 302 : 307];
    }
}
