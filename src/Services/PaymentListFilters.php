<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\ListQuery;

/**
 * Reads the payments list filters from the URL and writes them back.
 *
 * Invalid values are dropped rather than rejected, and 'adjusted' tells the
 * page to show a "some filters were reset" notice.
 */
final class PaymentListFilters
{
    /**
     * @return array{filters: array<string, ?string>, page: int, adjusted: bool}
     */
    public static function normalize(array $query): array
    {
        $adjusted = false;
        $search = trim(ListQuery::string($query, 'q', $adjusted));
        if (Text::length($search) > 100) {
            $search = substr($search, 0, 100);
            $adjusted = true;
        }
        $status = ListQuery::allowed($query, 'status', PaymentService::STATUSES, $adjusted);
        $method = ListQuery::allowed($query, 'method', PaymentService::METHODS, $adjusted);
        $dateFrom = ListQuery::date($query, 'date_from', $adjusted);
        $dateTo = ListQuery::date($query, 'date_to', $adjusted);
        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            $dateFrom = $dateTo = null;
            $adjusted = true;
        }
        $pageText = ListQuery::string($query, 'page', $adjusted);
        $page = $pageText === '' ? 1 : filter_var($pageText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        if ($page === false) {
            $page = 1;
            $adjusted = true;
        }

        return [
            'filters' => [
                'search' => $search === '' ? null : $search,
                'status' => $status,
                'method' => $method,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'page' => (int) $page,
            'adjusted' => $adjusted,
        ];
    }

    /**
     * The URL query that reproduces the current filters and page (empty values omitted).
     *
     * @param array{filters: array<string, ?string>} $normalized the result of normalize()
     * @return array<string, string|int>
     */
    public static function queryState(array $normalized, int $page): array
    {
        $filters = $normalized['filters'];

        return array_filter([
            'q' => $filters['search'],
            'status' => $filters['status'],
            'method' => $filters['method'],
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'page' => $page,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
