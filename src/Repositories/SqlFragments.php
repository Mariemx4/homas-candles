<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Small, safe building blocks for repository SQL.
 *
 * Values always travel as bound parameters; these helpers only prepare the
 * parameter text or generate parameter *names*, never user text inside SQL.
 */
final class SqlFragments
{
    /**
     * Make a search term literal inside a LIKE pattern.
     *
     * The queries declare `ESCAPE '='`, so `%` and `_` typed by a person are
     * matched as characters instead of wildcards. Callers add their own
     * wildcards afterwards, e.g. `'%' . SqlFragments::escapeLike($search) . '%'`.
     * Only use it with a query that says `ESCAPE '='`.
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(['=', '%', '_'], ['==', '=%', '=_'], $value);
    }

    /**
     * Named placeholders for an `IN (...)` list of integer IDs.
     *
     *     [$in, $params] = SqlFragments::idPlaceholders([4, 9], 'order');
     *     // $in = ':order0, :order1', $params = ['order0' => 4, 'order1' => 9]
     *
     * Every ID is cast to int. Callers must handle an empty list themselves:
     * `IN ()` is invalid SQL.
     *
     * @param array<int|string> $ids
     * @return array{0: string, 1: array<string, int>}
     */
    public static function idPlaceholders(array $ids, string $prefix): array
    {
        $names = [];
        $parameters = [];
        foreach (array_values($ids) as $index => $id) {
            $name = $prefix . $index;
            $names[] = ':' . $name;
            $parameters[$name] = (int) $id;
        }

        return [implode(', ', $names), $parameters];
    }
}
