<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Business service for Production Service; it contains application rules and coordinates related persistence work.
 *
 * DATA FLOW: Controllers call this layer. It validates and normalizes domain input, calls repositories or storage collaborators, and returns structured results without rendering HTML.
 */

namespace App\Services;

use App\Repositories\ProductionRepository;
use DateTimeImmutable;

final class ProductionService
{
    public const STATUSES = ProductionRepository::QUEUE_STATUSES;
    public const METHODS = ['DELIVERY', 'PICKUP'];
    public const SORTS = ['priority', 'required_date', 'created_at', 'total'];
    public const DIRECTIONS = ['asc', 'desc'];
    public const PER_PAGE = 20;
    private const SHEET_ORDER_CAP = 200;

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(private readonly ProductionRepository $production)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function queue(array $query, DateTimeImmutable $today): array
    {
        $adjusted = false;
        $shared = $this->normalizeSharedFilters($query, $adjusted);
        $search = trim($this->string($query, 'q', $adjusted));
        if (strlen($search) > 100) {
            $search = substr($search, 0, 100);
            $adjusted = true;
        }
        $dateFrom = $this->date($query, 'date_from', $adjusted);
        $dateTo = $this->date($query, 'date_to', $adjusted);
        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            $dateFrom = null;
            $dateTo = null;
            $adjusted = true;
        }
        $sort = $this->allowed($query, 'sort', self::SORTS, $adjusted, 'priority');
        $direction = $this->allowed($query, 'direction', self::DIRECTIONS, $adjusted, 'asc');
        $page = $this->page($query, $adjusted);

        $filters = $shared + [
            'search' => $search === '' ? null : $search,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];

        $total = $this->production->countForQueue($filters);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $totalPages);
        $rows = $total === 0 ? [] : $this->production->findForQueue(
            $filters,
            $sort,
            $direction,
            ($page - 1) * self::PER_PAGE,
            self::PER_PAGE,
            $today->format('Y-m-d')
        );

        foreach ($rows as &$row) {
            $row['timing'] = $this->timing((string) $row['required_date'], $today);
        }
        unset($row);

        $state = $this->queueQueryState($filters, $sort, $direction, $page);

        return [
            'orders' => $rows,
            'filters' => $filters,
            'sort' => $sort,
            'direction' => $direction,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $total,
            'totalPages' => $totalPages,
            'queryState' => $state,
            // URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
            'returnQuery' => http_build_query($state),
            'filtersAdjusted' => $adjusted,
            'filterOptions' => $this->filterOptions(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function calendar(array $query, DateTimeImmutable $today): array
    {
        $adjusted = false;
        $shared = $this->normalizeSharedFilters($query, $adjusted);
        $monthText = trim($this->string($query, 'month', $adjusted));
        $monthStart = $this->monthStart($monthText, $today, $adjusted);

        $monthStartValue = $monthStart->format('Y-m-d');
        $monthEndExclusiveValue = $monthStart->modify('+1 month')->format('Y-m-d');
        $counts = $this->production->countsByDate($shared, $monthStartValue, $monthEndExclusiveValue);
        $countsByDate = [];
        foreach ($counts as $row) {
            $countsByDate[(string) $row['required_date']] = (int) $row['order_count'];
        }

        $weeks = $this->buildMonthGrid($monthStart, $countsByDate, $today);

        return [
            'month' => $monthStart->format('Y-m'),
            'monthLabel' => $monthStart->format('F Y'),
            'previousMonth' => $monthStart->modify('-1 month')->format('Y-m'),
            'nextMonth' => $monthStart->modify('+1 month')->format('Y-m'),
            'weeks' => $weeks,
            'filters' => $shared,
            'filterQuery' => $this->sharedQueryState($shared),
            'filterOptions' => $this->filterOptions(),
            'filtersAdjusted' => $adjusted,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sheet(array $query, DateTimeImmutable $today): array
    {
        $adjusted = false;
        $shared = $this->normalizeSharedFilters($query, $adjusted);
        $dateFrom = $this->date($query, 'date_from', $adjusted) ?? $today->format('Y-m-d');
        $dateTo = $this->date($query, 'date_to', $adjusted) ?? $dateFrom;
        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
            $adjusted = true;
        }

        $filters = $shared + ['search' => null, 'date_from' => $dateFrom, 'date_to' => $dateTo];

        $total = $this->production->countForQueue($filters);
        $capped = $total > self::SHEET_ORDER_CAP;
        $orders = $this->production->findForScope($filters, self::SHEET_ORDER_CAP);
        // COLLECTION IDIOM: Transform every array item and return a new array without changing the original one.
        $orderIds = array_map(static fn (array $order): int => (int) $order['id'], $orders);

        $lines = $this->production->findProductionLines($orderIds);
        [$toProduce, $toAssemble] = $this->groupProductionLines($lines);

        // Custom-recipe groups carry their own recipe, so only catalogue groups
        // are checked for a missing catalogue recipe.
        // COLLECTION IDIOM: Transform every array item and return a new array without changing the original one.
        $produceProductIds = array_values(array_unique(array_map(
            static fn (array $group): int => $group['product_id'],
            array_filter($toProduce, static fn (array $group): bool => !$group['custom_recipe'] && $group['product_id'] > 0)
        )));
        $missingRecipeProducts = $produceProductIds === []
            ? []
            : $this->production->findMissingRecipeProducts($produceProductIds);
        // COLLECTION IDIOM: Transform every array item and return a new array without changing the original one.
        $missingRecipeIds = array_map(static fn (array $product): int => (int) $product['id'], $missingRecipeProducts);

        $materialRows = $this->production->findMaterialRequirements($orderIds);
        $materials = [];
        $shortages = [];
        foreach ($materialRows as $row) {
            $required = (string) $row['required_quantity'];
            $available = (string) $row['stock_quantity'];
            $short = $this->moneyToMillis($required) > $this->moneyToMillis($available);
            $entry = [
                'material_id' => (int) $row['material_id'],
                'sku' => $row['sku'],
                'name' => $row['name'],
                'unit' => $row['unit'],
                'required_quantity' => $required,
                'stock_quantity' => $available,
                'shortfall' => $short ? $this->subtractMoney($required, $available) : null,
            ];
            $materials[] = $entry;
            if ($short) {
                $shortages[] = $entry;
            }
        }

        $orphanedItemCount = $orderIds === [] ? 0 : $this->production->countOrphanedItems($orderIds);

        return [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'orders' => $orders,
            'orderCount' => count($orders),
            'totalMatching' => $total,
            'capped' => $capped,
            'toProduce' => $toProduce,
            'toAssemble' => $toAssemble,
            'missingRecipeProductIds' => $missingRecipeIds,
            'missingRecipeProducts' => $missingRecipeProducts,
            'materials' => $materials,
            'shortages' => $shortages,
            'orphanedItemCount' => $orphanedItemCount,
            // COLLECTION IDIOM: Keep only array items for which the callback returns true.
            'filterQuery' => $this->sharedQueryState($shared) + array_filter(
                ['date_from' => $dateFrom, 'date_to' => $dateTo],
                static fn (mixed $value): bool => $value !== null && $value !== ''
            ),
            'filtersAdjusted' => $adjusted,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filterOptions(): array
    {
        return [
            'products' => $this->production->findFilterProducts(),
            'scents' => $this->production->findFilterScents(),
            'colors' => $this->production->findFilterColors(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function groupProductionLines(array $lines): array
    {
        $produceGroups = [];
        $assembleGroups = [];

        $addLine = static function (array &$groups, array $line): void {
            $notes = trim((string) ($line['customization_notes'] ?? ''));
            // A custom-recipe item is identified by its own snapshot name, not
            // by a catalogue product, so it never merges with catalogue lines.
            $custom = (int) ($line['custom_recipe'] ?? 0) === 1;
            $key = implode('|', [
                $custom ? 'custom:' . (string) $line['product_name'] : (string) $line['product_id'],
                (string) ($line['selected_color_id'] ?? ''),
                (string) ($line['selected_scent_id'] ?? ''),
                $notes,
            ]);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'product_id' => (int) $line['product_id'],
                    'custom_recipe' => $custom,
                    'product_name' => (string) $line['product_name'],
                    'color_name' => $line['color_name_snapshot'],
                    'scent_name' => $line['scent_name_snapshot'],
                    'customization_notes' => $notes === '' ? null : $notes,
                    'quantity' => 0,
                    'order_numbers' => [],
                ];
            }
            $groups[$key]['quantity'] += (int) $line['quantity'];
            $orderNumber = (string) $line['order_number'];
            if (!in_array($orderNumber, $groups[$key]['order_numbers'], true)) {
                $groups[$key]['order_numbers'][] = $orderNumber;
            }
        };

        foreach ($lines as $line) {
            if ((string) $line['line_kind'] === 'ASSEMBLE') {
                $addLine($assembleGroups, $line);
            } else {
                $addLine($produceGroups, $line);
            }
        }

        $sort = static function (array $groups): array {
            $groups = array_values($groups);
            usort($groups, static fn (array $a, array $b): int => $a['product_name'] <=> $b['product_name']);

            return $groups;
        };

        return [$sort($produceGroups), $sort($assembleGroups)];
    }

    /**
     * @return array{label: string, tone: string}
     */
    private function timing(string $requiredDate, DateTimeImmutable $today): array
    {
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

    private function monthStart(string $value, DateTimeImmutable $today, bool &$adjusted): DateTimeImmutable
    {
        // VALIDATION IDIOM: Test the complete string against a regular-expression format before accepting it.
        if ($value !== '' && preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value . '-01');
            if ($date instanceof DateTimeImmutable && $date->format('Y-m') === $value) {
                return $date;
            }
        }
        if ($value !== '') {
            $adjusted = true;
        }

        $fallback = DateTimeImmutable::createFromFormat('!Y-m-d', $today->format('Y-m') . '-01');

        return $fallback instanceof DateTimeImmutable ? $fallback : $today;
    }

    /**
     * FUNCTION PURPOSE: Build month grid from already validated inputs.
     *
     * @param array<string, int> $countsByDate
     * @return list<list<array<string, mixed>>>
     */
    private function buildMonthGrid(DateTimeImmutable $monthStart, array $countsByDate, DateTimeImmutable $today): array
    {
        $todayValue = $today->format('Y-m-d');
        $monthValue = $monthStart->format('Y-m');
        $firstWeekday = (int) $monthStart->format('N');
        $gridStart = $monthStart->modify('-' . ($firstWeekday - 1) . ' days');
        $daysInMonth = (int) $monthStart->format('t');
        $lastDay = $monthStart->modify('+' . ($daysInMonth - 1) . ' days');
        $lastWeekday = (int) $lastDay->format('N');
        $gridEnd = $lastDay->modify('+' . (7 - $lastWeekday) . ' days');

        $weeks = [];
        $week = [];
        $cursor = $gridStart;
        while ($cursor <= $gridEnd) {
            $dateValue = $cursor->format('Y-m-d');
            $count = $countsByDate[$dateValue] ?? 0;
            $week[] = [
                'date' => $dateValue,
                'day' => (int) $cursor->format('j'),
                'inMonth' => $cursor->format('Y-m') === $monthValue,
                'isToday' => $dateValue === $todayValue,
                'isOverdue' => $dateValue < $todayValue && $count > 0,
                'count' => $count,
            ];
            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $weeks;
    }

    /**
     * FUNCTION PURPOSE: Validate and convert shared filters into a consistent internal shape.
     *
     * @return array{status: ?string, method: ?string, product_id: ?int, scent_id: ?int, color_id: ?int}
     */
    private function normalizeSharedFilters(array $query, bool &$adjusted): array
    {
        return [
            'status' => $this->allowed($query, 'status', self::STATUSES, $adjusted),
            'method' => $this->allowed($query, 'method', self::METHODS, $adjusted),
            'product_id' => $this->positiveInt($query, 'product', $adjusted),
            'scent_id' => $this->positiveInt($query, 'scent', $adjusted),
            'color_id' => $this->positiveInt($query, 'color', $adjusted),
        ];
    }

    /**
     * @param array<string, mixed> $filters @return array<string, string>
     */
    private function sharedQueryState(array $filters): array
    {
        // COLLECTION IDIOM: Keep only array items for which the callback returns true.
        return array_filter([
            'status' => $filters['status'],
            'method' => $filters['method'],
            'product' => $filters['product_id'] === null ? null : (string) $filters['product_id'],
            'scent' => $filters['scent_id'] === null ? null : (string) $filters['scent_id'],
            'color' => $filters['color_id'] === null ? null : (string) $filters['color_id'],
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param array<string, mixed> $filters @return array<string, string>
     */
    private function queueQueryState(array $filters, string $sort, string $direction, int $page): array
    {
        // COLLECTION IDIOM: Keep only array items for which the callback returns true.
        return array_filter([
            'q' => $filters['search'],
            'status' => $filters['status'],
            'method' => $filters['method'],
            'product' => $filters['product_id'] === null ? null : (string) $filters['product_id'],
            'scent' => $filters['scent_id'] === null ? null : (string) $filters['scent_id'],
            'color' => $filters['color_id'] === null ? null : (string) $filters['color_id'],
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'sort' => $sort,
            'direction' => $direction,
            'page' => (string) $page,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * FUNCTION PURPOSE: Accept ed only when it belongs to the allowed set.
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

    /**
     * FUNCTION PURPOSE: Convert int into a positive integer or reject it.
     */
    private function positiveInt(array $query, string $key, bool &$adjusted): ?int
    {
        $value = $this->string($query, $key, $adjusted);
        if ($value === '') {
            return null;
        }
        // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($int === false) {
            $adjusted = true;

            return null;
        }

        return (int) $int;
    }

    private function page(array $query, bool &$adjusted): int
    {
        $value = $this->string($query, 'page', $adjusted);
        // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
        $page = $value === '' ? 1 : filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000000],
        ]);
        if ($page === false) {
            $page = 1;
            $adjusted = true;
        }

        return (int) $page;
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

        return ((int) $matches[1] * 1000) + (int) str_pad($matches[2] ?? '', 3, '0');
    }

    /**
     * FUNCTION PURPOSE: Subtract money using exact integer money arithmetic.
     */
    private function subtractMoney(string $minuend, string $subtrahend): string
    {
        $remaining = max(0, $this->moneyToMillis($minuend) - $this->moneyToMillis($subtrahend));

        return intdiv($remaining, 1000) . '.' . str_pad((string) ($remaining % 1000), 3, '0', STR_PAD_LEFT);
    }
}
