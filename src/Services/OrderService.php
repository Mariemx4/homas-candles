<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrderRepository;
use DateTimeImmutable;

final class OrderService
{
    public const STATUSES = [
        'REQUESTED', 'CONFIRMED', 'PREPARING', 'PREPARED',
        'WAITING_FOR_PICKUP', 'PICKED_UP', 'IN_DELIVERY', 'DELIVERED', 'CANCELLED',
    ];
    public const SOURCES = ['CART', 'MANUAL'];
    public const METHODS = ['DELIVERY', 'PICKUP'];
    public const SORTS = ['required_date', 'created_at', 'total'];
    public const DIRECTIONS = ['asc', 'desc'];
    public const PER_PAGE = 20;


    public function __construct(private readonly OrderRepository $orders)
    {
    }


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
            $row['deadline'] = in_array($row['order_status'], ['PICKED_UP', 'DELIVERED', 'CANCELLED'], true)
                ? $row['timing']
                : DeadlinePresentation::forDate((string) $row['required_date'], $today);
            $row['paymentSummary'] = $this->paymentSummary($row);
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
            'returnQuery' => http_build_query($state),
            'filtersAdjusted' => $normalized['adjusted'],
            'hasActiveFilters' => count(array_filter($filters, static fn (mixed $value): bool => $value !== null && $value !== '')) > 0,
        ];
    }

    /** Read-only presentation of verified payments; pending payments never count as paid. */
    private function paymentSummary(array $order): array
    {
        $label = PaymentService::balanceLabel((string) $order['total_amount'], (string) $order['amount_paid'], (int) $order['pending_payment_count'] > 0);
        $summary = ['label' => $label, 'tone' => match ($label) {
            'Credit/refund due', 'Pending verification' => 'warning',
            'Paid' => 'success', 'Partially paid' => 'info', default => 'neutral',
        }];

        return $summary + ['hasPending' => (int) $order['pending_payment_count'] > 0];
    }


    public function detail(int $orderId, DateTimeImmutable $today): ?array
    {
        $order = $this->orders->findSummary($orderId);
        if ($order === null) {
            return null;
        }

        $order['timing'] = $this->timing((string) $order['required_date'], (string) $order['order_status'], $today);
        $order['remaining_balance'] = Money::remainingAmount(
            (string) $order['total_amount'],
            (string) $order['amount_paid']
        );
        $order['credit_balance'] = Money::remainingAmount((string) $order['amount_paid'], (string) $order['total_amount']);
        $order['deposit_due'] = Money::remainingAmount((string)$order['required_deposit_amount'], (string)$order['amount_paid']);
        if ($order['order_status'] === 'CANCELLED') {
            $order['remaining_balance'] = $order['deposit_due'] = '0.000';
            $order['credit_balance'] = Money::formatMillis(Money::parseMillis((string)$order['amount_paid']) ?? 0);
        }
        $order['payment_status_label'] = PaymentService::balanceLabel((string) $order['total_amount'], (string) $order['amount_paid'], (string) $order['pending_amount']);
        $payments = $this->orders->findPayments($orderId);
        $order['payment_methods_label'] = PaymentService::recordedMethods($payments);
        $items = $this->orders->findItems($orderId);
        $cost = 0;
        $costKnown = true;
        foreach ($items as $item) {
            $unit = Money::parseMillis((string)($item['unit_cost_snapshot'] ?? ''));
            if ($unit === null) {
                $costKnown = false;
                break;
            }
            $cost += $unit * (int)$item['quantity'];
        }
        $order['admin_estimate'] = $costKnown ? AdminReportService::grossEstimate(
            (Money::parseMillis((string)$order['total_amount']) ?? 0) - (Money::parseMillis((string)$order['delivery_fee']) ?? 0),
            $cost
        ) : null;

        return [
            'order' => $order,
            'items' => $items,
            'payments' => $payments,
            'history' => $this->orders->findStatusHistory($orderId),
            'customerEdits' => $this->orders->customerEdits($orderId),
        ];
    }


    public function normalizeListQuery(array $query): array
    {
        $adjusted = false;
        $search = $this->string($query, 'q', $adjusted);
        $search = trim($search);
        if (strlen($search) > 100) {
            $search = substr($search, 0, 100);
            $adjusted = true;
        }

        $status = $this->allowed($query, 'status', [...self::STATUSES, 'INCOMPLETE'], $adjusted);
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
        return http_build_query($this->queryState($normalized, (int) $normalized['page']));
    }


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

        return array_filter($state, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array{label: string, tone: string}
     */
    private function timing(string $requiredDate, string $status, DateTimeImmutable $today): array
    {
        if ($status === 'CANCELLED') {
            return ['label' => 'Cancelled','tone' => 'neutral'];
        }
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
