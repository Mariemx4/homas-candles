<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Business service for Order Service; it contains application rules and coordinates related persistence work.
 *
 * DATA FLOW: Controllers call this layer. It validates and normalizes domain input, calls repositories or storage collaborators, and returns structured results without rendering HTML.
 */

namespace App\Services;

use App\Repositories\OrderRepository;
use DateTimeImmutable;

final class OrderService
{
    public const STATUSES = [
        'REQUESTED', 'CONFIRMED', 'PREPARING', 'PREPARED',
        'WAITING_FOR_PICKUP', 'PICKED_UP', 'IN_DELIVERY', 'DELIVERED',
    ];
    public const SOURCES = ['CART', 'MANUAL'];
    public const METHODS = ['DELIVERY', 'PICKUP'];
    public const SORTS = ['required_date', 'created_at', 'total'];
    public const DIRECTIONS = ['asc', 'desc'];
    public const PER_PAGE = 20;

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(private readonly OrderRepository $orders)
    {
    }

    /**
     * FUNCTION PURPOSE: Prepare a filtered and paginated collection from the supplied query options.
     *
     * @return array<string, mixed>
     */
    public function list(array $query, DateTimeImmutable $today): array
    {
        $normalized = $this->normalizeListQuery($query);
        $filters = $normalized['filters'];
        $total = $this->orders->countForList($filters);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($normalized['page'], $totalPages);
        $rows = $total === 0 ? [] : $this->orders->findForList(
            $filters,
            $normalized['sort'],
            $normalized['direction'],
            ($page - 1) * self::PER_PAGE,
            self::PER_PAGE
        );

        foreach ($rows as &$row) {
            $row['timing'] = $this->timing((string) $row['required_date'], (string) $row['order_status'], $today);
        }
        unset($row);

        $state = $this->queryState($normalized, $page);

        return [
            'orders' => $rows,
            'filters' => $filters,
            'sort' => $normalized['sort'],
            'direction' => $normalized['direction'],
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $total,
            'totalPages' => $totalPages,
            'queryState' => $state,
            // URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
            'returnQuery' => http_build_query($state),
            'filtersAdjusted' => $normalized['adjusted'],
        ];
    }

    /**
     * FUNCTION PURPOSE: Load and assemble all data needed by one detail screen.
     *
     * @return array<string, mixed>|null
     */
    public function detail(int $orderId, DateTimeImmutable $today): ?array
    {
        $order = $this->orders->findSummary($orderId);
        if ($order === null) {
            return null;
        }

        $order['timing'] = $this->timing((string) $order['required_date'], (string) $order['order_status'], $today);
        $order['remaining_balance'] = $this->subtractMoney(
            (string) $order['total_amount'],
            (string) $order['amount_paid']
        );
        $order['credit_balance'] = $this->subtractMoney((string) $order['amount_paid'], (string) $order['total_amount']);

        return [
            'order' => $order,
            'items' => $this->orders->findItems($orderId),
            'payments' => $this->orders->findPayments($orderId),
            'history' => $this->orders->findStatusHistory($orderId),
        ];
    }

    /**
     * FUNCTION PURPOSE: Validate and convert list query into a consistent internal shape.
     *
     * @return array<string, mixed>
     */
    public function normalizeListQuery(array $query): array
    {
        $adjusted = false;
        $search = $this->string($query, 'q', $adjusted);
        $search = trim($search);
        if (strlen($search) > 100) {
            $search = substr($search, 0, 100);
            $adjusted = true;
        }

        $status = $this->allowed($query, 'status', self::STATUSES, $adjusted);
        $source = $this->allowed($query, 'source', self::SOURCES, $adjusted);
        $method = $this->allowed($query, 'method', self::METHODS, $adjusted);
        $dateFrom = $this->date($query, 'date_from', $adjusted);
        $dateTo = $this->date($query, 'date_to', $adjusted);
        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            $dateFrom = null;
            $dateTo = null;
            $adjusted = true;
        }

        $sort = $this->allowed($query, 'sort', self::SORTS, $adjusted, 'required_date');
        $direction = $this->allowed($query, 'direction', self::DIRECTIONS, $adjusted, 'asc');
        $pageValue = $this->string($query, 'page', $adjusted);
        // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
        $page = $pageValue === '' ? 1 : filter_var($pageValue, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000000],
        ]);
        if ($page === false) {
            $page = 1;
            $adjusted = true;
        }

        return [
            'filters' => [
                'search' => $search === '' ? null : $search,
                'status' => $status,
                'source' => $source,
                'method' => $method,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'sort' => $sort,
            'direction' => $direction,
            'page' => (int) $page,
            'adjusted' => $adjusted,
        ];
    }

    /**
     * FUNCTION PURPOSE: Validate and convert d return query into a consistent internal shape.
     */
    public function normalizedReturnQuery(string $rawQuery): string
    {
        if ($rawQuery === '' || strlen($rawQuery) > 2000) {
            return '';
        }
        parse_str($rawQuery, $query);
        if (!is_array($query)) {
            return '';
        }
        $normalized = $this->normalizeListQuery($query);

        // URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
        return http_build_query($this->queryState($normalized, (int) $normalized['page']));
    }

    /**
     * FUNCTION PURPOSE: Read and normalize the state query value.
     *
     * @param array<string, mixed> $normalized
     */
    private function queryState(array $normalized, int $page): array
    {
        $filters = $normalized['filters'];
        $state = [
            'q' => $filters['search'],
            'status' => $filters['status'],
            'source' => $filters['source'],
            'method' => $filters['method'],
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'sort' => $normalized['sort'],
            'direction' => $normalized['direction'],
            'page' => $page,
        ];

        // COLLECTION IDIOM: Keep only array items for which the callback returns true.
        return array_filter($state, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array{label: string, tone: string}
     */
    private function timing(string $requiredDate, string $status, DateTimeImmutable $today): array
    {
        if (in_array($status, ['PICKED_UP', 'DELIVERED'], true)) {
            return ['label' => 'Complete', 'tone' => 'neutral'];
        }

        $todayValue = $today->format('Y-m-d');
        if ($requiredDate < $todayValue) {
            return ['label' => 'Overdue', 'tone' => 'danger'];
        }
        if ($requiredDate === $todayValue) {
            return ['label' => 'Due today', 'tone' => 'warning'];
        }
        if ($requiredDate <= $today->modify('+7 days')->format('Y-m-d')) {
            return ['label' => 'Due soon', 'tone' => 'info'];
        }

        return ['label' => 'Scheduled', 'tone' => 'neutral'];
    }

    /**
     * FUNCTION PURPOSE: Subtract money using exact integer money arithmetic.
     */
    private function subtractMoney(string $total, string $paid): string
    {
        $remaining = max(0, $this->moneyToMillis($total) - $this->moneyToMillis($paid));

        return intdiv($remaining, 1000) . '.' . str_pad((string) ($remaining % 1000), 3, '0', STR_PAD_LEFT);
    }

    /**
     * FUNCTION PURPOSE: Convert to millis using integer thousandths to avoid floating-point errors.
     */
    private function moneyToMillis(string $amount): int
    {
        // VALIDATION IDIOM: Test the complete string against a regular-expression format before accepting it.
        if (preg_match('/^(\d+)(?:\.(\d{1,3}))?$/', $amount, $matches) !== 1) {
            return 0;
        }
        $fraction = str_pad($matches[2] ?? '', 3, '0');

        return ((int) $matches[1] * 1000) + (int) $fraction;
    }

    private function string(array $query, string $key, bool &$adjusted): string
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
     * FUNCTION PURPOSE: Accept ed only when it belongs to the allowed set.
     *
     * @param list<string> $allowed
     */
    private function allowed(
        array $query,
        string $key,
        array $allowed,
        bool &$adjusted,
        ?string $default = null
    ): ?string {
        $value = $this->string($query, $key, $adjusted);
        if ($value === '') {
            return $default;
        }
        if (!in_array($value, $allowed, true)) {
            $adjusted = true;
            return $default;
        }

        return $value;
    }

    private function date(array $query, string $key, bool &$adjusted): ?string
    {
        $value = $this->string($query, $key, $adjusted);
        if ($value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            $adjusted = true;
            return null;
        }

        return $value;
    }
}
