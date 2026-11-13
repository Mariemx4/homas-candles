<?php

declare(strict_types=1);

namespace App\Services;

/** Private, explicitly saved work for the current administrator session. */
final class AdminWorkDrafts
{
    private static function key(string $kind, int $actor): string
    {
        if (!in_array($kind, ['order','request'], true) || $actor < 1) {
            throw new \InvalidArgumentException('Invalid work draft.');
        }
        return $actor . ':' . $kind;
    }
    public static function save(string $kind, int $actor, array $values): array
    {
        if (strlen(json_encode($values, JSON_THROW_ON_ERROR)) > 2097152) {
            throw new \DomainException('This draft is too large to save. Keep the brief within 2 MiB.');
        }
        unset($values['csrf_token'],$values['password'],$values['work_draft_id']);
        $draft = ['id' => bin2hex(random_bytes(16)),'saved_at' => time(),'values' => $values];
        $_SESSION['_admin_work_drafts'][self::key($kind, $actor)] = $draft;
        return $draft;
    }
    public static function load(string $kind, int $actor): ?array
    {
        $key = self::key($kind, $actor);
        $draft = $_SESSION['_admin_work_drafts'][$key] ?? null;
        if (!is_array($draft) || !is_int($draft['saved_at'] ?? null) || $draft['saved_at'] < time() - 10800) {
            unset($_SESSION['_admin_work_drafts'][$key]);
            return null;
        }
        return $draft;
    }
    public static function forget(string $kind, int $actor, ?string $id = null): void
    {
        $key = self::key($kind, $actor);
        $draft = $_SESSION['_admin_work_drafts'][$key] ?? null;
        if ($id === null || (is_array($draft) && hash_equals($draft['id'], $id))) {
            unset($_SESSION['_admin_work_drafts'][$key]);
        }
    }
}
