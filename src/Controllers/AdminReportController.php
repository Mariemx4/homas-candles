<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Application controller for Admin Report Controller; it coordinates a use case for the HTTP-facing layer.
 *
 * DATA FLOW: A public entry point calls this controller with request-ready input. The controller delegates rules to services and returns data for a redirect, response, or view.
 */

namespace App\Controllers;

use App\Services\AdminReportService;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

final class AdminReportController
{
    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(private readonly AdminReportService $reports)
    {
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `index` (index) and return its result.
     *
     * @return array<string, mixed>
     */
    public function index(array $query, ?DateTimeImmutable $today = null): array
    {
        try {
            return $this->reports->report($query, $today) + ['loadError' => null, 'httpStatus' => 200];
        } catch (Throwable) {
            error_log('The administrator operational report could not be loaded.');

            return [
                'filters' => ['start' => '', 'end' => '', 'section' => 'orders', 'page' => 1, 'errors' => []],
                'summary' => [], 'top' => [], 'details' => [],
                'pagination' => ['page' => 1, 'totalPages' => 1, 'total' => 0, 'perPage' => AdminReportService::PER_PAGE],
                'definitions' => [],
                'loadError' => 'Reports are temporarily unavailable. Please try again.',
                'httpStatus' => 503,
            ];
        }
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `export` (export) and return its result.
     *
     * @return array<string, mixed>
     */
    public function export(array $query, ?DateTimeImmutable $today = null): array
    {
        try {
            return $this->reports->export($query, $today) + ['error' => null, 'httpStatus' => 200];
        } catch (InvalidArgumentException $exception) {
            return ['rows' => [], 'columns' => [], 'filters' => [], 'error' => $exception->getMessage(), 'httpStatus' => 422];
        } catch (Throwable) {
            error_log('An administrator report export could not be generated.');

            return ['rows' => [], 'columns' => [], 'filters' => [], 'error' => 'The export is temporarily unavailable.', 'httpStatus' => 503];
        }
    }
}
