<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Business service for Admin Dashboard Service; it contains application rules and coordinates related persistence work.
 *
 * DATA FLOW: Controllers call this layer. It validates and normalizes domain input, calls repositories or storage collaborators, and returns structured results without rendering HTML.
 */

namespace App\Services;

use App\Repositories\AdminDashboardRepository;
use DateTimeImmutable;

final class AdminDashboardService
{
    /** Receive the repository used for every dashboard database query. */
    public function __construct(private readonly AdminDashboardRepository $dashboard)
    {
    }

    /**
     * Assemble one view model containing dashboard counts and short lists.
     *
     * A "view model" is simply an array shaped for the screen. Keeping this
     * assembly here means the template does not need to understand SQL or make
     * independent database calls.
     *
     * @return array<string, mixed> Dashboard data keyed by the names used in
     *     `app/Views/Pages/admin-dashboard.php`.
     */
    public function overview(DateTimeImmutable $today): array
    {
        // DateTimeImmutable returns a new object from modify(), leaving the
        // original `$today` unchanged. SQL receives stable YYYY-MM-DD strings.
        $throughDate = $today->modify('+7 days');
        $todayValue = $today->format('Y-m-d');
        $throughValue = $throughDate->format('Y-m-d');

        // Fetch reusable lists once. The first low-stock row is also reused in
        // the summary card, avoiding a second query for the same information.
        $lowestStock = $this->dashboard->findLowestTrackedMaterials();
        $urgentOrders = $this->dashboard->findUrgentOrders($todayValue, $throughValue);

        // These key names form the contract between this service and the view.
        return [
            'today' => $todayValue,
            'throughDate' => $throughValue,
            'metrics' => [
                'requestedOrders' => $this->dashboard->countRequestedOrders(),
                'ordersDueSoon' => $this->dashboard->countOrdersDueSoon($todayValue, $throughValue),
                'pendingPayments' => $this->dashboard->countPendingPayments(),
                'openRequests' => $this->dashboard->countOpenRequests(),
                'lowestTrackedStock' => $lowestStock[0] ?? null,
            ],
            'urgentActions' => $this->urgentActions($urgentOrders, $todayValue),
            'upcomingOrders' => $this->dashboard->findUpcomingOrders($todayValue),
            'recentRequests' => $this->dashboard->findRecentOpenRequests(),
            'pendingPayments' => $this->dashboard->findPendingPayments(),
            'lowestStockMaterials' => $lowestStock,
        ];
    }

    /**
     * Convert raw urgent order rows into small display-ready action records.
     *
     * @param list<array<string, mixed>> $orders
     * @return list<array<string, mixed>>
     */
    private function urgentActions(array $orders, string $today): array
    {
        $actions = [];
        foreach ($orders as $order) {
            $requiredDate = (string) $order['required_date'];

            // YYYY-MM-DD strings sort chronologically, so these comparisons
            // correctly identify dates before and equal to today.
            $isOverdue = $requiredDate < $today;
            $isToday = $requiredDate === $today;

            // Nested ternaries choose one of three labels/tones. The result is
            // normalized here so the view only needs to print the values.
            $actions[] = [
                'kind' => $isOverdue ? 'Overdue' : ($isToday ? 'Due today' : 'Due soon'),
                'tone' => $isOverdue ? 'danger' : ($isToday ? 'warning' : 'neutral'),
                'title' => (string) $order['order_number'],
                'detail' => (string) $order['customer_name_snapshot'],
                'date' => $requiredDate,
                'status' => (string) $order['order_status'],
            ];
        }

        return $actions;
    }
}
