<?php

declare(strict_types=1);

namespace App\Services;

final class PublicAssetPathResolver
{
    private string|false $publicRoot;
    private string|false $allowedRoot;


    public function __construct(string $publicRoot, string $allowedDirectory)
    {
        $this->publicRoot = realpath($publicRoot);
        $this->allowedRoot = realpath($allowedDirectory);
    }


    public function resolve(?string $relativePath): ?string
    {
        if ($relativePath === null || $relativePath === '') {
            return null;
        }

        $absolutePath = $this->publicRoot === false
            ? false
            : realpath($this->publicRoot . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\'));

        if ($this->publicRoot === false || $this->allowedRoot === false || $absolutePath === false) {
            return null;
        }

        $boundary = rtrim($this->allowedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($absolutePath, $boundary)) {
            return null;
        }

        return str_replace('\\', '/', substr($absolutePath, strlen($this->publicRoot) + 1));
    }
}
