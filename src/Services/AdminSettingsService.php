<?php

declare(strict_types=1);

namespace App\Services;

final class AdminSettingsService
{
    public function __construct(
        private readonly string $publicDirectory,
        private readonly string $backupDirectory,
        private readonly string $backupScript
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function capabilities(): array
    {
        $restore = $this->backupStorageProtected() ? \App\Maintenance\PostgresBackup::latestVerified($this->backupDirectory) : null;
        return [
            'storeSettingsWritable' => false,
            'administratorManagementAvailable' => true,
            // Script presence does not prove a compatible, restore-tested backup.
            'backupMechanismAvailable' => $restore !== null,
            'lastRestoreCheck' => $restore,
            'backupStorageProtected' => $this->backupStorageProtected(),
            'backupCommand' => 'php scripts/backup-database.php',
        ];
    }

    public function backupStorageProtected(): bool
    {
        $public = realpath($this->publicDirectory);
        $backup = realpath($this->backupDirectory);
        if ($public === false || $backup === false) {
            return false;
        }

        return !$this->contains($public, $backup);
    }

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
