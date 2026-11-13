<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

/** Bounded HTTPS JSON requests. Redirects are forbidden so secrets stay at their destination. */
final class CurlJsonHttpClient implements JsonHttpClient
{
    public function request(string $method, string $url, #[\SensitiveParameter] array $form = []): array
    {
        if (!in_array($method, ['GET', 'POST'], true) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('Unsupported identity request.');
        }
        $body = '';
        $maxAge = null;
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 262144) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$maxAge): int {
                if (stripos($line, 'Cache-Control:') === 0 && preg_match('/max-age=(\d+)/i', $line, $match)) {
                    $maxAge = min(3600, (int) $match[1]);
                }
                return strlen($line);
            },
        ]);
        if ($method === 'POST') {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($form, '', '&', PHP_QUERY_RFC3986));
        }
        $ok = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($ok === false || $status !== 200) {
            throw new RuntimeException('The identity provider could not be reached.');
        }
        $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid identity provider response.');
        }
        return ['body' => $decoded, 'max_age' => $maxAge];
    }
}
