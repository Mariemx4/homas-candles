<?php

declare(strict_types=1);

/** Tiny loopback-only HTTP client that follows PHP session-ID rotations in tests. */
final class HttpTestClient
{
    private string $cookie = '';

    public function __construct(private readonly string $base)
    {
        if (preg_match('~^http://127\.0\.0\.1:[0-9]+$~D', $base) !== 1) {
            throw new InvalidArgumentException('A disposable loopback server is required.');
        }
    }

    public function request(string $path, ?array $post = null, ?string $method = null): array
    {
        $headers = $this->cookie === '' ? [] : ['Cookie: ' . $this->cookie];
        if ($post !== null) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $context = stream_context_create(['http' => [
            'method' => $method ?? ($post === null ? 'GET' : 'POST'), 'header' => implode("\r\n", $headers),
            'content' => $post === null ? '' : http_build_query($post), 'ignore_errors' => true,
            'follow_location' => 0, 'timeout' => 10,
        ]]);
        $body = file_get_contents($this->base . '/' . ltrim($path, '/'), false, $context);
        $responseHeaders = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? []);
        preg_match('/\s([0-9]{3})\s/', $responseHeaders[0] ?? '', $status);
        $location = null;
        foreach ($responseHeaders as $header) {
            if (preg_match('/^Set-Cookie:\s*(PHPSESSID=[^;]*)/i', $header, $match)) {
                $this->cookie = $match[1];
            }
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, 9));
            }
        }
        return ['status' => (int) ($status[1] ?? 0), 'body' => (string) $body,
            'location' => $location, 'headers' => $responseHeaders];
    }

    public static function field(string $html, string $name): string
    {
        if (!preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]+)"/', $html, $match)) {
            throw new RuntimeException('Expected form field was not rendered: ' . $name);
        }
        return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
    }
}
