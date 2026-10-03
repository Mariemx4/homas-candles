<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Application controller for Custom Request Controller; it coordinates a use case for the HTTP-facing layer.
 *
 * DATA FLOW: A public entry point calls this controller with request-ready input. The controller delegates rules to services and returns data for a redirect, response, or view.
 */

namespace App\Controllers;

use App\Security\CsrfTokenManager;
use App\Services\CustomRequestConversionService;
use App\Services\CustomRequestService;
use DateTimeImmutable;
use Throwable;

final class CustomRequestController
{
    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly CustomRequestService $requests,
        private readonly CustomRequestConversionService $conversion,
        private readonly CsrfTokenManager $csrf
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function index(array $query): array
    {
        try {
            return $this->requests->list($query, new DateTimeImmutable()) + ['loadError' => null];
        } catch (Throwable) {
            error_log('The administrator custom request inbox could not be loaded.');
            return [
                'requests' => [], 'categories' => [], 'administrators' => [],
                'filters' => ['search' => null, 'status' => null, 'category' => null, 'administrator' => null, 'date_from' => null, 'date_to' => null],
                'page' => 1, 'perPage' => CustomRequestService::PER_PAGE, 'total' => 0, 'totalPages' => 1,
                'queryState' => [], 'returnQuery' => '', 'filtersAdjusted' => false,
                'loadError' => 'Custom requests are temporarily unavailable. Please try again.',
            ];
        }
    }

    /**
     * @return array{detail: array<string, mixed>|null, httpStatus: int, backQuery: string}
     */
    public function show(array $query): array
    {
        $requestId = $this->positiveId($query['id'] ?? null);
        $returnQuery = isset($query['return']) && is_string($query['return']) && strlen($query['return']) <= 2000
            ? $query['return'] : '';
        if ($requestId === null) {
            return ['detail' => null, 'httpStatus' => 400, 'backQuery' => ''];
        }
        try {
            $detail = $this->requests->detail($requestId, new DateTimeImmutable());
        } catch (Throwable) {
            error_log('An administrator custom request detail could not be loaded.');
            return ['detail' => null, 'httpStatus' => 503, 'backQuery' => $returnQuery];
        }

        return ['detail' => $detail, 'httpStatus' => $detail === null ? 404 : 200, 'backQuery' => $returnQuery];
    }

    /**
     * FUNCTION PURPOSE: Handle the action request branch and return a consistent result.
     *
     * @return array<string, mixed>
     */
    public function processAction(array $post, int $administratorId): array
    {
        $requestId = $this->positiveId($post['id'] ?? null);
        $action = $this->postString($post, 'action');
        $values = [];
        $token = isset($post['csrf_token']) && is_string($post['csrf_token']) ? $post['csrf_token'] : null;
        $validCsrf = $this->csrf->validate($token);
        $this->csrf->rotate();
        if (!$validCsrf) {
            return $this->actionResult(false, $requestId, ['form' => 'The form expired. Review the request and try again.'], $values);
        }
        if ($requestId === null) {
            return $this->actionResult(false, null, ['form' => 'The request identifier is invalid.'], $values);
        }

        try {
            if ($action === 'assign') {
                $rawAdministrator = $this->postString($post, 'administrator_id');
                $assignedId = $rawAdministrator === '' ? null : $this->positiveId($rawAdministrator);
                if ($rawAdministrator !== '' && $assignedId === null) {
                    return $this->actionResult(false, $requestId, ['assignment' => 'Select an active administrator.'], ['action' => $action]);
                }
                $result = $this->requests->assign($requestId, $assignedId);
                $values = ['action' => $action, 'administrator_id' => $rawAdministrator];
            } elseif ($action === 'transition') {
                $status = strtoupper($this->postString($post, 'new_status'));
                $method = strtoupper($this->postString($post, 'contact_method'));
                $notes = $this->postString($post, 'notes');
                $result = $this->requests->transition($requestId, $administratorId, $status, $method, $notes);
                $values = ['action' => $action, 'new_status' => $status, 'contact_method' => $method, 'notes' => $notes];
            } elseif ($action === 'contact') {
                $method = strtoupper($this->postString($post, 'contact_method'));
                $outcome = strtoupper($this->postString($post, 'contact_outcome'));
                $notes = $this->postString($post, 'notes');
                $followUp = $this->postString($post, 'follow_up');
                $result = $this->requests->recordContact(
                    $requestId, $administratorId, $method, $outcome, $notes, $followUp, new DateTimeImmutable()
                );
                $values = ['action' => $action, 'contact_method' => $method, 'contact_outcome' => $outcome, 'notes' => $notes, 'follow_up' => $followUp];
            } else {
                return $this->actionResult(false, $requestId, ['form' => 'The requested action is not supported.'], []);
            }
        } catch (Throwable) {
            error_log('An administrator custom request action could not be completed.');
            return $this->actionResult(false, $requestId, ['form' => 'The request could not be updated. Please try again.'], $values);
        }

        return $this->actionResult($result['success'], $requestId, $result['errors'], $values, $action);
    }

    /**
     * FUNCTION PURPOSE: Handle the conversion request branch and return a consistent result.
     *
     * @return array<string, mixed>
     */
    public function processConversion(array $post, int $administratorId): array
    {
        $requestId = $this->positiveId($post['id'] ?? null);
        $values = [];
        foreach (['product_name', 'quantity', 'agreed_batch_price', 'unit_price', 'customization_notes', 'delivery_method', 'required_date', 'delivery_fee', 'street_address', 'delegation', 'governorate', 'postal_code', 'administrator_notes'] as $key) {
            $values[$key] = $this->postString($post, $key);
        }
        $token = isset($post['csrf_token']) && is_string($post['csrf_token']) ? $post['csrf_token'] : null;
        $validCsrf = $this->csrf->validate($token);
        $this->csrf->rotate();
        if (!$validCsrf) {
            return ['success' => false, 'requestId' => $requestId, 'errors' => ['form' => 'The conversion form expired. Review it and try again.'], 'values' => $values];
        }
        if ($requestId === null) {
            return ['success' => false, 'requestId' => null, 'errors' => ['form' => 'The request identifier is invalid.'], 'values' => $values];
        }
        try {
            $result = $this->conversion->convert($requestId, $administratorId, $values);
        } catch (Throwable) {
            error_log('An agreed custom request could not be converted into an order.');
            $result = ['success' => false, 'errors' => ['form' => 'The conversion could not be completed. No order was created.']];
        }

        return $result + ['requestId' => $requestId, 'values' => $values];
    }

    /**
     * Validate the CSRF boundary and pass safe form values to request creation.
     * The controller handles HTTP-shaped input; domain validation stays in the service.
     *
     * @return array<string, mixed>
     */
    public function processCreation(array $post, int $administratorId): array
    {
        $values = [];
        foreach (['customer_mode', 'customer_id', 'account_street_address', 'account_delegation', 'account_governorate', 'account_postal_code', 'copy_account_address', 'existing_customer_name', 'existing_customer_email', 'existing_customer_phone', 'guest_customer_name', 'guest_customer_email', 'guest_customer_phone', 'new_customer_name', 'new_customer_email', 'new_customer_phone', 'new_customer_second_phone', 'customer_name', 'customer_email', 'customer_phone', 'preferred_contact_method',
            'category_id', 'assigned_administrator_id', 'budget', 'requested_quantity', 'required_date',
            'color_preferences', 'scent_preferences', 'packaging_preferences', 'customer_message'] as $key) {
            $values[$key] = $this->postString($post, $key);
        }
        $token = isset($post['csrf_token']) && is_string($post['csrf_token']) ? $post['csrf_token'] : null;
        $validCsrf = $this->csrf->validate($token);
        $this->csrf->rotate();
        if (!$validCsrf) {
            return ['success' => false, 'errors' => ['form' => 'The request form expired. Review it and try again.'], 'values' => $values];
        }
        foreach (['existing_customer_name', 'existing_customer_email', 'existing_customer_phone'] as $key) { if (!array_key_exists($key, $post)) unset($values[$key]); }
        try {
            $result = $this->requests->create($administratorId, $values);
        } catch (Throwable) {
            error_log('An administrator-created custom request could not be saved.');
            $result = ['success' => false, 'errors' => ['form' => 'The custom request could not be created. No data was saved.']];
        }

        return $result + ['values' => $values];
    }

    /** @return array<string, mixed> */
    public function creationOptions(): array
    {
        return $this->requests->creationOptions();
    }

    /**
     * @return array<string, mixed>
     */
    public function conversionDefaults(array $request): array
    {
        return $this->conversion->defaults($request);
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

    private function postString(array $post, string $key): string
    {
        return isset($post[$key]) && is_string($post[$key]) ? $post[$key] : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function actionResult(bool $success, ?int $requestId, array $errors, array $values, string $action = ''): array
    {
        return ['success' => $success, 'requestId' => $requestId, 'errors' => $errors, 'values' => $values, 'action' => $action];
    }
}
