<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Business service for Payment Evidence Storage; it contains application rules and coordinates related persistence work.
 *
 * DATA FLOW: Controllers call this layer. It validates and normalizes domain input, calls repositories or storage collaborators, and returns structured results without rendering HTML.
 */

namespace App\Services;

use finfo;

// WHAT: saves and serves D17 payment-evidence files outside the public web
//       root, with a filename this app generates itself.
// WHY:  proof-of-transfer images/PDFs are sensitive order evidence — they
//       must never be reachable by a guessable /public/... URL, and the
//       stored extension must come from the file's real content, not from
//       the client-supplied name, so an uploaded file can never execute.
final class PaymentEvidenceStorage
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    private const ALLOWED_MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    private readonly string $directory;

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/\\');
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0750, true);
        }
    }

    /**
     * @param array{name?: mixed, type?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed}|null $file
     * @return array{success: bool, path?: string|null, error?: string}
     */
    public function store(?array $file): array
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            return ['success' => true, 'path' => null];
        }
        if (!is_int($error) || $error !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'The evidence file could not be uploaded.'];
        }

        $tmpName = $file['tmp_name'] ?? null;
        $size = $file['size'] ?? null;
        if (!is_string($tmpName) || $tmpName === '' || !is_uploaded_file($tmpName)) {
            return ['success' => false, 'error' => 'The evidence file upload is invalid.'];
        }
        $actualSize = filesize($tmpName);
        if (!is_int($size) || $size <= 0 || $size > self::MAX_BYTES || $actualSize === false || $actualSize <= 0 || $actualSize > self::MAX_BYTES) {
            return ['success' => false, 'error' => 'The evidence file must be 5 MB or smaller.'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpName);
        $extension = is_string($mimeType) ? (self::ALLOWED_MIME_EXTENSIONS[$mimeType] ?? null) : null;
        if ($extension === null) {
            return ['success' => false, 'error' => 'Evidence must be a JPEG, PNG, WEBP image, or PDF file.'];
        }
        if (str_starts_with($mimeType, 'image/')) {
            $image = @getimagesize($tmpName);
            if ($image === false || ($image['mime'] ?? null) !== $mimeType) {
                return ['success' => false, 'error' => 'The payment proof image is invalid.'];
            }
        }

        $filename = bin2hex(random_bytes(20)) . '.' . $extension;
        $destination = $this->directory . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($tmpName, $destination)) {
            return ['success' => false, 'error' => 'The evidence file could not be saved.'];
        }
        chmod($destination, 0640);

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

    // WHAT: turns a stored filename back into a safe absolute path.
    // WHY:  filenames are always our own bin2hex()+known-extension output,
    //       but this still refuses any value containing a path separator
    //       and re-checks the result stays inside the evidence directory.
    public function resolve(?string $relativePath): ?string
    {
        if (!is_string($relativePath) || $relativePath === '') {
            return null;
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

    public function mimeType(string $absolutePath): string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($absolutePath);

        return is_string($mimeType) ? $mimeType : 'application/octet-stream';
    }
}
