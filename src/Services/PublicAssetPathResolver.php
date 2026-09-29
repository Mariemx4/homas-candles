<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Business service for Public Asset Path Resolver; it contains application rules and coordinates related persistence work.
 *
 * DATA FLOW: Controllers call this layer. It validates and normalizes domain input, calls repositories or storage collaborators, and returns structured results without rendering HTML.
 */

namespace App\Services;

final class PublicAssetPathResolver
{
    private string|false $publicRoot;
    private string|false $allowedRoot;

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(string $publicRoot, string $allowedDirectory)
    {
        $this->publicRoot = realpath($publicRoot);
        $this->allowedRoot = realpath($allowedDirectory);
    }

    /**
     * FUNCTION PURPOSE: Resolve the supplied value into the safe concrete value needed by the caller.
     */
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
