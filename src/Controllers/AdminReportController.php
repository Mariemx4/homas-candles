<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AdminReportService;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

final class AdminReportController
{
    public function __construct(private readonly AdminReportService $reports)
    {
    }

    /**
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
