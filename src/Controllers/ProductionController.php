<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Application controller for Production Controller; it coordinates a use case for the HTTP-facing layer.
 *
 * DATA FLOW: A public entry point calls this controller with request-ready input. The controller delegates rules to services and returns data for a redirect, response, or view.
 */

namespace App\Controllers;

use App\Services\ProductionService;
use DateTimeImmutable;
use Throwable;

final class ProductionController
{
    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(private readonly ProductionService $production)
    {
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `queue` (queue) and return its result.
     *
     * @return array<string, mixed>
     */
    public function queue(array $query, ?DateTimeImmutable $today = null): array
    {
        try {
            return $this->production->queue($query, $today ?? new DateTimeImmutable('today')) + [
                'loadError' => null,
                'httpStatus' => 200,
            ];
        } catch (Throwable) {
            error_log('The production queue could not be loaded.');

            return [
                'orders' => [],
                'filters' => [
                    'search' => null, 'status' => null, 'method' => null,
                    'product_id' => null, 'scent_id' => null, 'color_id' => null,
                    'date_from' => null, 'date_to' => null,
                ],
                'sort' => 'priority',
                'direction' => 'asc',
                'page' => 1,
                'perPage' => ProductionService::PER_PAGE,
                'total' => 0,
                'totalPages' => 1,
                'queryState' => [],
                'returnQuery' => '',
                'filtersAdjusted' => false,
                'filterOptions' => ['products' => [], 'scents' => [], 'colors' => []],
                'loadError' => 'The production queue is temporarily unavailable. Please refresh or try again later.',
                'httpStatus' => 503,
            ];
        }
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `calendar` (calendar) and return its result.
     *
     * @return array<string, mixed>
     */
    public function calendar(array $query, ?DateTimeImmutable $today = null): array
    {
        $today ??= new DateTimeImmutable('today');
        try {
            return $this->production->calendar($query, $today) + [
                'loadError' => null,
                'httpStatus' => 200,
            ];
        } catch (Throwable) {
            error_log('The production calendar could not be loaded.');

            return [
                'month' => $today->format('Y-m'),
                'monthLabel' => $today->format('F Y'),
                'previousMonth' => $today->modify('-1 month')->format('Y-m'),
                'nextMonth' => $today->modify('+1 month')->format('Y-m'),
                'weeks' => [],
                'filters' => ['status' => null, 'method' => null, 'product_id' => null, 'scent_id' => null, 'color_id' => null],
                'filterQuery' => [],
                'filterOptions' => ['products' => [], 'scents' => [], 'colors' => []],
                'filtersAdjusted' => false,
                'loadError' => 'The production calendar is temporarily unavailable. Please refresh or try again later.',
                'httpStatus' => 503,
            ];
        }
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `sheet` (sheet) and return its result.
     *
     * @return array<string, mixed>
     */
    public function sheet(array $query, ?DateTimeImmutable $today = null): array
    {
        try {
            return $this->production->sheet($query, $today ?? new DateTimeImmutable('today')) + [
                'loadError' => null,
                'httpStatus' => 200,
            ];
        } catch (Throwable) {
            error_log('The production preparation sheet could not be loaded.');

            return [
                'dateFrom' => null,
                'dateTo' => null,
                'filters' => ['status' => null, 'method' => null, 'product_id' => null, 'scent_id' => null, 'color_id' => null],
                'filterOptions' => ['products' => [], 'scents' => [], 'colors' => []],
                'orders' => [],
                'orderCount' => 0,
                'totalMatching' => 0,
                'capped' => false,
                'toProduce' => [],
                'toAssemble' => [],
                'missingRecipeProductIds' => [],
                'missingRecipeProducts' => [],
                'materials' => [],
                'shortages' => [],
                'orphanedItemCount' => 0,
                'filterQuery' => [],
                'filtersAdjusted' => false,
                'loadError' => 'The preparation sheet is temporarily unavailable. Please refresh or try again later.',
                'httpStatus' => 503,
            ];
        }
    }
}
