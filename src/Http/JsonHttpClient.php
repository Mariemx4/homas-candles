<?php

declare(strict_types=1);

namespace App\Http;

interface JsonHttpClient
{
    /** @return array{body: array, max_age: int|null} */
    public function request(string $method, string $url, #[\SensitiveParameter] array $form = []): array;
}
