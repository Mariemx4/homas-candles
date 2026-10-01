<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Business service for Admin Customer Service; it contains application rules and coordinates related persistence work.
 *
 * DATA FLOW: Controllers call this layer. It validates and normalizes domain input, calls repositories or storage collaborators, and returns structured results without rendering HTML.
 */

namespace App\Services;

use App\Repositories\AdminCustomerRepository;

final class AdminCustomerService
{
    public const DIRECTORY_PER_PAGE = 20;
    public const HISTORY_PER_PAGE = 10;
    public const MAX_DIRECTORY_RESULTS = 100;
    public const MIN_SEARCH_LENGTH = 2;

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(private readonly AdminCustomerRepository $customers)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function directory(array $query): array
    {
        $normalized = $this->normalizeDirectoryQuery($query);
        $search = $normalized['search'];
        if ($search === null) {
            return [
                'customers' => [],
                'search' => '',
                'searchRequired' => true,
                'page' => 1,
                'perPage' => self::DIRECTORY_PER_PAGE,
                'total' => 0,
                'totalPages' => 1,
                'capped' => false,
                'queryState' => [],
                'filtersAdjusted' => $normalized['adjusted'],
            ];
        }

        $count = $this->customers->countForSearch($search);
        $capped = $count > self::MAX_DIRECTORY_RESULTS;
        $total = min($count, self::MAX_DIRECTORY_RESULTS);
        $totalPages = max(1, (int) ceil($total / self::DIRECTORY_PER_PAGE));
        $page = min($normalized['page'], $totalPages);
        $rows = $total === 0 ? [] : $this->customers->findForSearch(
            $search,
            ($page - 1) * self::DIRECTORY_PER_PAGE,
            self::DIRECTORY_PER_PAGE
        );

        return [
            'customers' => $rows,
            'search' => $search,
            'searchRequired' => false,
            'page' => $page,
            'perPage' => self::DIRECTORY_PER_PAGE,
            'total' => $total,
            'totalPages' => $totalPages,
            'capped' => $capped,
            'queryState' => ['q' => $search, 'page' => $page],
            'filtersAdjusted' => $normalized['adjusted'],
        ];
    }

    /**
     * FUNCTION PURPOSE: Load and assemble all data needed by one detail screen.
     *
     * @return array<string, mixed>|null
     */
    public function detail(int $customerId, array $query): ?array
    {
        $customer = $this->customers->findAccount($customerId);
        if ($customer === null) {
            return null;
        }

        $ordersPageInput = $this->page($query, 'orders_page');
        $requestsPageInput = $this->page($query, 'requests_page');
        $orderTotal = $this->customers->countOrders($customerId);
        $requestTotal = $this->customers->countRequests($customerId);
        $orderPages = max(1, (int) ceil($orderTotal / self::HISTORY_PER_PAGE));
        $requestPages = max(1, (int) ceil($requestTotal / self::HISTORY_PER_PAGE));
        $ordersPage = min($ordersPageInput['page'], $orderPages);
        $requestsPage = min($requestsPageInput['page'], $requestPages);

        return [
            'customer' => $customer,
            'addresses' => $this->customers->findAddresses($customerId),
            'metrics' => $this->customers->findMetrics($customerId),
            'orders' => $orderTotal === 0 ? [] : $this->customers->findOrders(
                $customerId,
                ($ordersPage - 1) * self::HISTORY_PER_PAGE,
                self::HISTORY_PER_PAGE
            ),
            'requests' => $requestTotal === 0 ? [] : $this->customers->findRequests(
                $customerId,
                ($requestsPage - 1) * self::HISTORY_PER_PAGE,
                self::HISTORY_PER_PAGE
            ),
            'orderPagination' => $this->pagination($ordersPage, $orderPages, $orderTotal),
            'requestPagination' => $this->pagination($requestsPage, $requestPages, $requestTotal),
            'pagesAdjusted' => $ordersPageInput['adjusted'] || $requestsPageInput['adjusted'],
        ];
    }

    /**
     * FUNCTION PURPOSE: Validate and convert d return query into a consistent internal shape.
     */
    public function normalizedReturnQuery(string $rawQuery): string
    {
        if ($rawQuery === '' || strlen($rawQuery) > 1000) {
            return '';
        }
        parse_str($rawQuery, $query);
        if (!is_array($query)) {
            return '';
        }
        $normalized = $this->normalizeDirectoryQuery($query);
        if ($normalized['search'] === null) {
            return '';
        }

        // URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
        return http_build_query(['q' => $normalized['search'], 'page' => $normalized['page']]);
    }

    /**
     * FUNCTION PURPOSE: Validate and convert directory query into a consistent internal shape.
     *
     * @return array{search: string|null, page: int, adjusted: bool}
     */
    private function normalizeDirectoryQuery(array $query): array
    {
        $adjusted = false;
        $raw = $this->queryString($query, 'q', $adjusted);
        $search = preg_replace('/\s+/u', ' ', trim($raw));
        if (!is_string($search)) {
            $search = '';
            $adjusted = true;
        }
        if ($this->length($search) > 100) {
            $search = substr($search, 0, 100);
            $adjusted = true;
        }
        if ($search !== '' && $this->length($search) < self::MIN_SEARCH_LENGTH) {
            $search = '';
            $adjusted = true;
        }

        $page = $this->page($query, 'page');

        $normalizedSearch = $search === '' ? '' : $this->normalizePhoneSearch($search);
        if ($normalizedSearch !== '' && $this->length($normalizedSearch) < self::MIN_SEARCH_LENGTH) {
            $normalizedSearch = '';
            $adjusted = true;
        }

        return [
            'search' => $normalizedSearch === '' ? null : $normalizedSearch,
            'page' => $page['page'],
            'adjusted' => $adjusted || $page['adjusted'],
        ];
    }

    /**
     * FUNCTION PURPOSE: Validate and convert phone search into a consistent internal shape.
     */
    private function normalizePhoneSearch(string $search): string
    {
        // VALIDATION IDIOM: Test the complete string against a regular-expression format before accepting it.
        if (preg_match('/^[+0-9() .-]+$/', $search) !== 1) {
            return $search;
        }
        $digits = preg_replace('/\D+/', '', $search);
        if (!is_string($digits)) {
            return $search;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '216')) {
            $digits = substr($digits, 3);
        }

        return $digits;
    }

    /**
     * @return array{page: int, adjusted: bool}
     */
    private function page(array $query, string $key): array
    {
        $adjusted = false;
        $value = $this->queryString($query, $key, $adjusted);
        // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
        $page = $value === '' ? 1 : filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000000],
        ]);
        if ($page === false) {
            $page = 1;
            $adjusted = true;
        }

        return ['page' => (int) $page, 'adjusted' => $adjusted];
    }

    /**
     * @return array{page: int, totalPages: int, total: int, perPage: int}
     */
    private function pagination(int $page, int $totalPages, int $total): array
    {
        return [
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'perPage' => self::HISTORY_PER_PAGE,
        ];
    }

    /**
     * FUNCTION PURPOSE: Read and normalize the string query value.
     */
    private function queryString(array $query, string $key, bool &$adjusted): string
    {
        if (!array_key_exists($key, $query)) {
            return '';
        }
        if (!is_string($query[$key])) {
            $adjusted = true;

            return '';
        }

        return $query[$key];
    }

    /**
     * FUNCTION PURPOSE: Count characters in the supplied value safely for UTF-8 validation.
     */
    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
