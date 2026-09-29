<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Business service for Admin Report Service; it contains application rules and coordinates related persistence work.
 *
 * DATA FLOW: Controllers call this layer. It validates and normalizes domain input, calls repositories or storage collaborators, and returns structured results without rendering HTML.
 */

namespace App\Services;

use App\Repositories\AdminReportRepository;
use DateTimeImmutable;
use InvalidArgumentException;

final class AdminReportService
{
    public const PER_PAGE = 25;
    public const MAX_EXPORT_ROWS = 5000;
    public const MAX_RANGE_DAYS = 366;
    public const SECTIONS = ['orders', 'payments', 'requests', 'materials'];

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(private readonly AdminReportRepository $reports)
    {
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `report` (report) and return its result.
     *
     * @return array<string, mixed>
     */
    public function report(array $query, ?DateTimeImmutable $today = null): array
    {
        $filters = $this->filters($query, $today, false);
        $bounds = $this->bounds($filters['start'], $filters['end']);
        $orderSummary = $this->decimalFields(
            $this->reports->orderSummary($bounds['start'], $bounds['end']),
            ['completed_value', 'known_cost']
        );
        $paymentSummary = $this->decimalFields(
            $this->reports->paymentSummary($bounds['start'], $bounds['end']),
            ['verified_amount']
        );
        $materialSummary = $this->decimalFields(
            $this->reports->materialSummary($bounds['start'], $bounds['end']),
            ['used_quantity', 'used_value', 'waste_quantity', 'waste_value']
        );
        $knownCost = (float) ($orderSummary['known_cost'] ?? 0);
        $completedValue = (float) ($orderSummary['completed_value'] ?? 0);
        $total = $this->reports->countDetails($filters['section'], $bounds['start'], $bounds['end']);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($filters['page'], $totalPages);

        $details = $total === 0 ? [] : $this->reports->findDetails(
            $filters['section'],
            $bounds['start'],
            $bounds['end'],
            ($page - 1) * self::PER_PAGE,
            self::PER_PAGE
        );

        return [
            'filters' => $filters + ['page' => $page],
            'bounds' => $bounds,
            'summary' => [
                'orders' => $orderSummary,
                'payments' => $paymentSummary,
                'requests' => $this->withConversionRate($this->reports->requestSummary($bounds['start'], $bounds['end'])),
                'materials' => $materialSummary,
                'estimated_profit' => number_format($completedValue - $knownCost, 3, '.', ''),
            ],
            'top' => [
                'products' => $this->reports->topItems('products', $bounds['start'], $bounds['end']),
                'scents' => $this->reports->topItems('scents', $bounds['start'], $bounds['end']),
                'colors' => $this->reports->topItems('colors', $bounds['start'], $bounds['end']),
            ],
            'details' => $this->normalizeDetails($filters['section'], $details),
            'pagination' => ['page' => $page, 'totalPages' => $totalPages, 'total' => $total, 'perPage' => self::PER_PAGE],
            'definitions' => $this->definitions(),
        ];
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `export` (export) and return its result.
     *
     * @return array{filters: array<string, mixed>, rows: list<array<string, mixed>>, columns: array<string, string>}
     */
    public function export(array $query, ?DateTimeImmutable $today = null): array
    {
        $filters = $this->filters($query, $today, true);
        $bounds = $this->bounds($filters['start'], $filters['end']);
        $total = $this->reports->countDetails($filters['section'], $bounds['start'], $bounds['end']);
        if ($total > self::MAX_EXPORT_ROWS) {
            throw new InvalidArgumentException('This export exceeds 5,000 rows. Shorten the date range.');
        }

        $rows = $total === 0 ? [] : $this->reports->findDetails(
            $filters['section'], $bounds['start'], $bounds['end'], 0, max(1, $total)
        );

        return [
            'filters' => $filters,
            'rows' => $this->normalizeDetails($filters['section'], $rows),
            'columns' => $this->exportColumns($filters['section']),
        ];
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `filters` (filters) and return its result.
     *
     * @return array<string, mixed>
     */
    private function filters(array $query, ?DateTimeImmutable $today, bool $strict): array
    {
        $today ??= new DateTimeImmutable('today');
        $defaultEnd = $today->format('Y-m-d');
        $defaultStart = $today->modify('-29 days')->format('Y-m-d');
        $errors = [];
        foreach (['start', 'end', 'section', 'page'] as $key) {
            if (array_key_exists($key, $query) && !is_string($query[$key])) {
                if ($strict) {
                    throw new InvalidArgumentException('Report filters must be scalar values.');
                }
                $query[$key] = '';
                $errors[] = 'A malformed report filter was reset.';
            }
        }
        $start = $this->queryString($query, 'start');
        $end = $this->queryString($query, 'end');
        $section = $this->queryString($query, 'section');
        $start = $start === '' ? $defaultStart : $start;
        $end = $end === '' ? $defaultEnd : $end;
        if ($section === '') {
            $section = 'orders';
        } elseif (!in_array($section, self::SECTIONS, true)) {
            if ($strict) {
                throw new InvalidArgumentException('The requested report section is not available.');
            }
            $section = 'orders';
            $errors[] = 'The report section was reset.';
        }

        $startDate = $this->date($start);
        $endDate = $this->date($end);
        if ($startDate === null || $endDate === null) {
            $errors[] = 'Dates must use the YYYY-MM-DD format.';
        } elseif ($startDate > $endDate) {
            $errors[] = 'The start date must not be after the end date.';
        } elseif ((int) $startDate->diff($endDate)->format('%a') + 1 > self::MAX_RANGE_DAYS) {
            $errors[] = 'Reports are limited to 366 inclusive days.';
        }
        if ($errors !== []) {
            if ($strict) {
                throw new InvalidArgumentException($errors[0]);
            }
            $start = $defaultStart;
            $end = $defaultEnd;
        }

        $rawPage = $this->queryString($query, 'page');
        // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
        $page = $rawPage === '' ? 1 : filter_var($rawPage, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000000],
        ]);
        if ($page === false) {
            $page = 1;
            $errors[] = 'The page number was reset.';
        }

        return ['start' => $start, 'end' => $end, 'section' => $section, 'page' => (int) $page, 'errors' => $errors];
    }

    /**
     * FUNCTION PURPOSE: Read and normalize the string query value.
     */
    private function queryString(array $query, string $key): string
    {
        return isset($query[$key]) && is_string($query[$key]) ? trim($query[$key]) : '';
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `date` (date) and return its result.
     */
    private function date(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `bounds` (bounds) and return its result.
     *
     * @return array{start: string, end: string}
     */
    private function bounds(string $start, string $end): array
    {
        return [
            'start' => $start . ' 00:00:00',
            'end' => (new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d') . ' 00:00:00',
        ];
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `withConversionRate` (with conversion rate) and return its result.
     *
     * @param array<string, mixed> $summary @return array<string, mixed>
     */
    private function withConversionRate(array $summary): array
    {
        $total = (int) ($summary['request_count'] ?? 0);
        $converted = (int) ($summary['converted_count'] ?? 0);
        $summary['conversion_rate'] = $total === 0 ? '0.0' : number_format(($converted / $total) * 100, 1, '.', '');

        return $summary;
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `decimalFields` (decimal fields) and return its result.
     *
     * @param array<string, mixed> $row @param list<string> $fields @return array<string, mixed>
     */
    private function decimalFields(array $row, array $fields): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = number_format((float) $row[$field], 3, '.', '');
            }
        }

        return $row;
    }

    /**
     * FUNCTION PURPOSE: Validate and convert details into a consistent internal shape.
     *
     * @param list<array<string, mixed>> $rows @return list<array<string, mixed>>
     */
    private function normalizeDetails(string $section, array $rows): array
    {
        // BRANCHING IDIOM: `match` selects exactly one result using strict comparisons and rejects/handles a default explicitly.
        $fields = match ($section) {
            'orders' => ['subtotal', 'delivery_fee', 'total_amount', 'known_cost'],
            'payments' => ['amount'],
            'materials' => ['quantity', 'unit_cost_snapshot', 'value_amount'],
            'requests' => [],
            default => throw new InvalidArgumentException('Unsupported report section.'),
        };

        // COLLECTION IDIOM: Transform every array item and return a new array without changing the original one.
        return array_map(fn (array $row): array => $this->decimalFields($row, $fields), $rows);
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `exportColumns` (export columns) and return its result.
     *
     * @return array<string, string>
     */
    private function exportColumns(string $section): array
    {
        // BRANCHING IDIOM: `match` selects exactly one result using strict comparisons and rejects/handles a default explicitly.
        return match ($section) {
            'orders' => ['order_number' => 'Order number', 'created_at' => 'Created at', 'order_source' => 'Source', 'order_status' => 'Status', 'subtotal' => 'Subtotal TND', 'delivery_fee' => 'Delivery fee TND', 'total_amount' => 'Total TND', 'known_cost' => 'Known item cost TND', 'missing_cost_items' => 'Items missing cost'],
            'payments' => ['order_number' => 'Order number', 'verified_at' => 'Verified at', 'payment_method' => 'Method', 'payment_type' => 'Type', 'amount' => 'Amount TND', 'currency' => 'Currency'],
            'requests' => ['request_number' => 'Request number', 'created_at' => 'Created at', 'request_status' => 'Status', 'converted_at' => 'Converted at'],
            'materials' => ['sku' => 'Material SKU', 'name' => 'Material', 'unit' => 'Unit', 'movement_type' => 'Movement type', 'quantity' => 'Quantity', 'unit_cost_snapshot' => 'Unit cost TND', 'value_amount' => 'Value TND', 'created_at' => 'Recorded at'],
            default => throw new InvalidArgumentException('Unsupported report section.'),
        };
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `definitions` (definitions) and return its result.
     *
     * @return array<string, string>
     */
    private function definitions(): array
    {
        return [
            'range' => 'Order and request metrics use each record’s created_at timestamp. Verified payments use verified_at. Material metrics use the ledger movement created_at. Both selected dates are inclusive.',
            'revenue' => 'Completed sales value sums total_amount for orders currently PICKED_UP or DELIVERED and created in the range. It is an order value, not confirmed cash receipt.',
            'payments' => 'Verified payments sum only VERIFIED payment rows whose verified_at is in range. Pending and rejected payments are excluded. Refunds are not modeled by the current schema.',
            'profit' => 'Estimated gross margin is completed sales value minus available order-item unit-cost snapshots. It excludes overhead and delivery expense and is incomplete when an item has no cost snapshot.',
            'top' => 'Top products, scents, and colors rank completed-order snapshot quantities. Empty scent or color selections are excluded; deleted catalogue links do not remove snapshots.',
            'requests' => 'Conversion rate is the share of requests created in range that currently have CONVERTED status and a linked order.',
            'materials' => 'Material use is gross ORDER_DEDUCTION quantity and snapshot value. Waste is gross DAMAGED_OR_WASTED quantity and snapshot value. Restocks are not netted against either figure.',
        ];
    }
}
