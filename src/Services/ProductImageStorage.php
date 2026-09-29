<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Business service for Product Image Storage; it contains application rules and coordinates related persistence work.
 *
 * DATA FLOW: Controllers call this layer. It validates and normalizes domain input, calls repositories or storage collaborators, and returns structured results without rendering HTML.
 */

namespace App\Services;

use finfo;

// WHAT: saves and replaces public-facing product/color photos under
//       public/assets/products/, with a filename this app generates itself.
// WHY:  product photos are meant to be public (unlike payment evidence), but
//       the stored extension must still come from the file's real content
//       so an uploaded file can never execute, and every upload gets a
//       fresh random name so replacing a photo never overwrites or deletes
//       a file another product/color row might still reference.
final class ProductImageStorage
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    private const ALLOWED_MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const PUBLIC_PREFIX = 'assets/products/';

    private readonly string $directory;

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/\\');
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `store` (store) and return its result.
     *
     * @param array{name?: mixed, type?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed}|null $file
     * @return array{success: bool, path?: string|null, error?: string}
     */
    public function store(?array $file): array
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if (!is_int($error) || $error === UPLOAD_ERR_NO_FILE) {
            return ['success' => true, 'path' => null];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'The image could not be uploaded.'];
        }

        $tmpName = $file['tmp_name'] ?? null;
        $size = $file['size'] ?? null;
        if (!is_string($tmpName) || $tmpName === '' || !is_uploaded_file($tmpName)) {
            return ['success' => false, 'error' => 'The image upload is invalid.'];
        }
        if (!is_int($size) || $size <= 0 || $size > self::MAX_BYTES) {
            return ['success' => false, 'error' => 'The image must be 5 MB or smaller.'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpName);
        $extension = is_string($mimeType) ? (self::ALLOWED_MIME_EXTENSIONS[$mimeType] ?? null) : null;
        if ($extension === null) {
            return ['success' => false, 'error' => 'The image must be a JPEG, PNG, or WEBP file.'];
        }

        $filename = bin2hex(random_bytes(20)) . '.' . $extension;
        $destination = $this->directory . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($tmpName, $destination)) {
            return ['success' => false, 'error' => 'The image could not be saved.'];
        }
        chmod($destination, 0644);

        return ['success' => true, 'path' => $filename];
    }

    /**
     * FUNCTION PURPOSE: Safely remove the stored the supplied value file when it is no longer referenced.
     */
    public function discard(?string $relativePath): void
    {
        $resolved = $this->resolve($relativePath);
        if ($resolved !== null) {
            @unlink($resolved);
        }
    }

    // WHAT: accepts either the bare generated filename or the full
    //       "assets/products/<filename>" form stored in the database.
    public function resolve(?string $relativePath): ?string
    {
        if (!is_string($relativePath) || $relativePath === '') {
            return null;
        }
        if (str_starts_with($relativePath, self::PUBLIC_PREFIX)) {
            $relativePath = substr($relativePath, strlen(self::PUBLIC_PREFIX));
        }
        if (str_contains($relativePath, '/') || str_contains($relativePath, '\\') || str_contains($relativePath, "\0")) {
            return null;
        }

        $boundary = realpath($this->directory);
        $candidate = realpath($this->directory . DIRECTORY_SEPARATOR . $relativePath);
        if ($boundary === false || $candidate === false) {
            return null;
        }
        $prefix = rtrim($boundary, '/\\') . DIRECTORY_SEPARATOR;
        if (!str_starts_with($candidate, $prefix)) {
            return null;
        }

        return $candidate;
    }

    /**
     * FUNCTION PURPOSE: Resolve the public relative path value used for safe file access.
     */
    public function publicRelativePath(string $storedFilename): string
    {
        return self::PUBLIC_PREFIX . $storedFilename;
    }
}
