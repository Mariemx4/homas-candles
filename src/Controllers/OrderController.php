<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Application controller for Order Controller; it coordinates a use case for the HTTP-facing layer.
 *
 * DATA FLOW: A public entry point calls this controller with request-ready input. The controller delegates rules to services and returns data for a redirect, response, or view.
 */

namespace App\Controllers;

use App\Services\OrderService;
use DateTimeImmutable;
use Throwable;

final class OrderController
{
    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(private readonly OrderService $orders)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function index(array $query, ?DateTimeImmutable $today = null): array
    {
        try {
            return $this->orders->list($query, $today ?? new DateTimeImmutable('today')) + [
                'loadError' => null,
                'httpStatus' => 200,
            ];
        } catch (Throwable) {
            error_log('The administrator order list could not be loaded.');

            return [
                'orders' => [],
                'filters' => [
                    'search' => null, 'status' => null, 'source' => null,
                    'method' => null, 'date_from' => null, 'date_to' => null,
                ],
                'sort' => 'required_date',
                'direction' => 'asc',
                'page' => 1,
                'perPage' => OrderService::PER_PAGE,
                'total' => 0,
                'totalPages' => 1,
                'queryState' => [],
                'returnQuery' => '',
                'filtersAdjusted' => false,
                'loadError' => 'Orders are temporarily unavailable. Please refresh or try again later.',
                'httpStatus' => 503,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function show(array $query, ?DateTimeImmutable $today = null): array
    {
        $orderId = $this->orderId($query['id'] ?? null);
        if ($orderId === null) {
            return $this->detailError(400, 'The order identifier is invalid.');
        }

        try {
            $detail = $this->orders->detail($orderId, $today ?? new DateTimeImmutable('today'));
            if ($detail === null) {
                return $this->detailError(404, 'The requested order could not be found.');
            }

            $rawReturn = isset($query['return']) && is_string($query['return']) ? $query['return'] : '';

            return $detail + [
                'backQuery' => $this->orders->normalizedReturnQuery($rawReturn),
                'loadError' => null,
                'httpStatus' => 200,
            ];
        } catch (Throwable) {
            error_log('The administrator order detail could not be loaded.');

            return $this->detailError(503, 'Order details are temporarily unavailable. Please try again later.');
        }
    }

    private function orderId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        // VALIDATION IDIOM: Test the complete string against a regular-expression format before accepting it.
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,18}$/', $value) !== 1) {
            return null;
        }
        // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : (int) $id;
    }

    /**
     * FUNCTION PURPOSE: Load and assemble all data needed by one detail screen.
     *
     * @return array<string, mixed>
     */
    private function detailError(int $status, string $message): array
    {
        return [
            'order' => null,
            'items' => [],
            'payments' => [],
            'history' => [],
            'backQuery' => '',
            'loadError' => $message,
            'httpStatus' => $status,
        ];
    }
}
