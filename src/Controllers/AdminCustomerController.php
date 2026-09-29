<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Application controller for Admin Customer Controller; it coordinates a use case for the HTTP-facing layer.
 *
 * DATA FLOW: A public entry point calls this controller with request-ready input. The controller delegates rules to services and returns data for a redirect, response, or view.
 */

namespace App\Controllers;

use App\Services\AdminCustomerService;
use Throwable;

final class AdminCustomerController
{
    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(private readonly AdminCustomerService $customers)
    {
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `index` (index) and return its result.
     *
     * @return array<string, mixed>
     */
    public function index(array $query): array
    {
        try {
            return $this->customers->directory($query) + ['loadError' => null, 'httpStatus' => 200];
        } catch (Throwable) {
            error_log('The administrator customer directory could not be loaded.');

            return [
                'customers' => [],
                'search' => '',
                'searchRequired' => true,
                'page' => 1,
                'perPage' => AdminCustomerService::DIRECTORY_PER_PAGE,
                'total' => 0,
                'totalPages' => 1,
                'capped' => false,
                'queryState' => [],
                'filtersAdjusted' => false,
                'loadError' => 'The customer directory is temporarily unavailable. Please try again.',
                'httpStatus' => 503,
            ];
        }
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `show` (show) and return its result.
     *
     * @return array{detail: array<string, mixed>|null, httpStatus: int, backQuery: string, loadError: string|null}
     */
    public function show(array $query): array
    {
        $customerId = $this->positiveId($query['id'] ?? null);
        $rawReturn = isset($query['return']) && is_string($query['return']) ? $query['return'] : '';
        $backQuery = $this->customers->normalizedReturnQuery($rawReturn);
        if ($customerId === null) {
            return ['detail' => null, 'httpStatus' => 400, 'backQuery' => $backQuery, 'loadError' => null];
        }
        try {
            $detail = $this->customers->detail($customerId, $query);
        } catch (Throwable) {
            error_log('An administrator customer detail could not be loaded.');

            return [
                'detail' => null,
                'httpStatus' => 503,
                'backQuery' => $backQuery,
                'loadError' => 'The customer record is temporarily unavailable. Please try again.',
            ];
        }

        return [
            'detail' => $detail,
            'httpStatus' => $detail === null ? 404 : 200,
            'backQuery' => $backQuery,
            'loadError' => null,
        ];
    }

    /**
     * FUNCTION PURPOSE: Convert id into a positive integer or reject it.
     */
    private function positiveId(mixed $value): ?int
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
}
