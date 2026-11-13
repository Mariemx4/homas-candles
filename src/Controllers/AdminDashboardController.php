<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AdminDashboardService;
use DateTimeImmutable;
use Throwable;

final class AdminDashboardController
{
    /**
     * Store the dashboard service that was constructed in the bootstrap file.
     *
     * `private readonly` means only this class can access the property and PHP
     * will not allow the property to be replaced after construction. This
     * shorthand both declares `$dashboard` and assigns the argument to it.
     */
    public function __construct(private readonly AdminDashboardService $dashboard)
    {
    }

    /**
     * Build all data needed by the dashboard view without letting a database
     * problem destroy the entire administrator page.
     *
     * Passing `$today` is optional. Production uses the real current day,
     * while tests can pass a fixed day and receive predictable results.
     *
     * @return array<string, mixed> Named values consumed by the dashboard view.
     */
    public function overview(?DateTimeImmutable $today = null): array
    {
        $today ??= \App\Services\BusinessClock::today();
        try {
            // Keep the same date in both successful and unavailable responses.
            return $this->dashboard->overview($today) + [
                // The `+` array-union operator adds this default only when the
                // service result does not already contain a `loadError` key.
                'loadError' => null,
                'httpStatus' => 200,
            ];
        } catch (Throwable) {
            // Repository/PDO details belong in the server log, not in HTML.
            // The administrator receives a safe message and empty collections
            // so every foreach/count operation in the view can still run.
            error_log('The administrator dashboard could not be loaded.');

            return [
                'loadError' => 'Dashboard data is temporarily unavailable. Please refresh or try again later.',
                'httpStatus' => 503,
                'today' => $today->format('Y-m-d'),
                'throughDate' => $today->modify('+7 days')->format('Y-m-d'),
                'metrics' => [],
                'urgentActions' => [],
                'upcomingOrders' => [],
                'recentRequests' => [],
                'pendingPayments' => [],
                'lowStockMaterials' => [],
                'unconfiguredMaterials' => 0,
            ];
        }
    }
}
