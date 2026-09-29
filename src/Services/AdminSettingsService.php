<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Business service for Admin Settings Service; it contains application rules and coordinates related persistence work.
 *
 * DATA FLOW: Controllers call this layer. It validates and normalizes domain input, calls repositories or storage collaborators, and returns structured results without rendering HTML.
 */

namespace App\Services;

final class AdminSettingsService
{
    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly string $publicDirectory,
        private readonly string $backupDirectory,
        private readonly string $backupScript
    ) {
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `capabilities` (capabilities) and return its result.
     *
     * @return array<string, mixed>
     */
    public function capabilities(): array
    {
        return [
            'storeSettingsWritable' => false,
            'administratorManagementAvailable' => false,
            'backupMechanismAvailable' => is_file($this->backupScript),
            'backupStorageProtected' => $this->backupStorageProtected(),
            'backupCommand' => 'php scripts/backup-database.php',
        ];
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `backupStorageProtected` (backup storage protected) and return its result.
     */
    public function backupStorageProtected(): bool
    {
        $public = realpath($this->publicDirectory);
        $backup = realpath($this->backupDirectory);
        if ($public === false || $backup === false) {
            return false;
        }

        return !$this->contains($public, $backup);
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `contains` (contains) and return its result.
     */
    private function contains(string $parent, string $candidate): bool
    {
        $parent = rtrim(str_replace('\\', '/', $parent), '/') . '/';
        $candidate = rtrim(str_replace('\\', '/', $candidate), '/') . '/';
        if (DIRECTORY_SEPARATOR === '\\') {
            $parent = strtolower($parent);
            $candidate = strtolower($candidate);
        }

        return str_starts_with($candidate, $parent);
    }
}
