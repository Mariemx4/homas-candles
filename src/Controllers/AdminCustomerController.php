<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\FormInput;
use App\Services\AdminCustomerService;
use Throwable;

final class AdminCustomerController
{
    public function __construct(private readonly AdminCustomerService $customers)
    {
    }

    /**
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
     * @return array{detail: array<string, mixed>|null, httpStatus: int, backQuery: string, loadError: string|null}
     */
    public function show(array $query): array
    {
        $customerId = FormInput::positiveId($query['id'] ?? null);
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


}
