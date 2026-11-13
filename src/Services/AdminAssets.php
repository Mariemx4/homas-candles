<?php

declare(strict_types=1);

namespace App\Services;

/** Version every stylesheet dependency so a child-file edit cannot stay stale. */
final class AdminAssets
{
    public static function styles(string $publicPath): array
    {
        $manifest = file_get_contents($publicPath . '/assets/css/pages/admin.css');
        preg_match_all("~@import\\s+url\\(['\"]?([^)'\"]+)~", (string)$manifest, $matches);
        $files = [];
        foreach ($matches[1] as $relative) {
            $path = realpath($publicPath . '/assets/css/pages/' . $relative);
            $root = realpath($publicPath . '/assets/css');
            if ($path === false || $root === false || !str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $root) . '/')) {
                continue;
            }
            $url = 'assets/css/' . str_replace('\\', '/', substr($path, strlen($root) + 1));
            $files[] = $url . '?v=' . substr(hash_file('sha256', $path), 0, 12);
        }
        return $files;
    }
}
