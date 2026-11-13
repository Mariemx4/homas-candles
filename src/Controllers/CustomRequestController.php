<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\FormInput;
use App\Security\CsrfTokenManager;
use App\Services\CustomRequestConversionService;
use App\Services\CustomRequestService;
use App\Services\CustomRequestPayload;
use DomainException;
use DateTimeImmutable;
use Throwable;

final class CustomRequestController
{
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
            return $this->requests->list($query, new DateTimeImmutable()) + ['loadError' => null, 'httpStatus' => 200];
        } catch (Throwable) {
            error_log('The administrator custom request inbox could not be loaded.');
            return $this->requests->unavailableList($query) + [
                'loadError' => 'Custom requests are temporarily unavailable. Please try again.',
                'httpStatus' => 503,
            ];
        }
    }

    /**
     * @return array{detail: array<string, mixed>|null, httpStatus: int, backQuery: string}
     */
    public function show(array $query): array
    {
        $requestId = FormInput::positiveId($query['id'] ?? null);
        $returnQuery = isset($query['return']) && is_string($query['return']) && strlen($query['return']) <= 2000
            ? $query['return'] : '';
        if ($requestId === null) {
            return ['detail' => null, 'httpStatus' => 400, 'backQuery' => ''];
        }
        try {
            $revisionPage = is_string($query['revision_page'] ?? null) ? filter_var($query['revision_page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1,'max_range' => 1000000]]) : 1;
            $detail = $this->requests->detail($requestId, new DateTimeImmutable(), $revisionPage === false ? 1 : $revisionPage);
        } catch (Throwable) {
            error_log('An administrator custom request detail could not be loaded.');
            return ['detail' => null, 'httpStatus' => 503, 'backQuery' => $returnQuery];
        }

        return ['detail' => $detail, 'httpStatus' => $detail === null ? 404 : 200, 'backQuery' => $returnQuery];
    }


    public function processAction(array $post, int $administratorId): array
    {
        $requestId = FormInput::positiveId($post['id'] ?? null);
        $action = $this->postString($post, 'action');
        $fields = match ($action) {
            'assign' => ['administrator_id'],
            'transition' => ['new_status', 'contact_method', 'notes'],
            'contact' => ['contact_method', 'contact_outcome', 'notes', 'follow_up'],
            default => [],
        };
        $values = ['action' => $action];
        foreach ($fields as $field) {
            $values[$field] = $this->postString($post, $field);
        }
        $token = FormInput::csrfToken($post);
        $validCsrf = $this->csrf->validate($token);
        $this->csrf->rotate();
        if (!$validCsrf) {
            return $this->actionResult(false, $requestId, ['form' => 'The form expired. Review the request and try again.'], $values);
        }
        if ($requestId === null) {
            return $this->actionResult(false, null, ['form' => 'The request identifier is invalid.'], $values);
        }

        try {
            if ($action === 'create_drafts') {
                $result = $this->conversion->createDrafts($requestId);
            } elseif ($action === 'assign') {
                $rawAdministrator = $this->postString($post, 'administrator_id');
                $assignedId = $rawAdministrator === '' ? null : FormInput::positiveId($rawAdministrator);
                if ($rawAdministrator !== '' && $assignedId === null) {
                    return $this->actionResult(false, $requestId, ['assignment' => 'Select an active administrator.'], $values);
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
                    $requestId,
                    $administratorId,
                    $method,
                    $outcome,
                    $notes,
                    $followUp,
                    new DateTimeImmutable()
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


    public function processConversion(array $post, int $administratorId): array
    {
        $payloadError = null;
        try {
            $post = CustomRequestPayload::decode($post);
        } catch (DomainException $error) {
            $payloadError = $error->getMessage();
        }
        $requestId = FormInput::positiveId($post['id'] ?? null);
        $values = [];
        foreach (['quote_total', 'product_name', 'quantity', 'agreed_batch_price', 'unit_price', 'customization_notes', 'delivery_method', 'required_date', 'delivery_fee', 'street_address', 'delegation', 'governorate', 'postal_code', 'administrator_notes'] as $key) {
            $values[$key] = $this->postString($post, $key);
        }
        if (array_key_exists('custom_products', $post)) {
            $values['custom_products'] = $this->safeProducts($post['custom_products'], ['definition_id','product_name','quantity','unit_price','agreed_batch_price','customization_notes']);
        }
        $token = FormInput::csrfToken($post);
        $validCsrf = $this->csrf->validate($token);
        $this->csrf->rotate();
        if (!$validCsrf) {
            return ['success' => false, 'requestId' => $requestId, 'errors' => ['form' => 'The conversion form expired. Review it and try again.'], 'values' => $values];
        }
        if ($requestId === null) {
            return ['success' => false, 'requestId' => null, 'errors' => ['form' => 'The request identifier is invalid.'], 'values' => $values];
        }
        if ($payloadError !== null) {
            return ['success' => false, 'requestId' => $requestId, 'errors' => ['form' => $payloadError], 'values' => $values];
        }
        try {
            if (($post['conversion_action'] ?? '') === 'review') {
                $result = $this->conversion->quote($requestId, $values);
                if ($result['success']) {
                    $values['quote_total'] = $result['totals']['total'];
                }
                return ['success' => false,'reviewed' => $result['success'],'errors' => $result['errors'],'requestId' => $requestId,'values' => $values];
            }
            $result = $this->conversion->convert($requestId, $administratorId, $values, $values['quote_total']);
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
    public function processCreation(array $post, int $administratorId, bool $saveDraft = false): array
    {
        $payloadError = null;
        try {
            $post = CustomRequestPayload::decode($post);
        } catch (DomainException $error) {
            $payloadError = $error->getMessage();
        }
        $values = [];
        foreach (['customer_mode', 'customer_id', 'account_street_address', 'account_delegation', 'account_governorate', 'account_postal_code', 'copy_account_address', 'existing_customer_name', 'existing_customer_email', 'existing_customer_phone', 'guest_customer_name', 'guest_customer_email', 'guest_customer_phone', 'new_customer_name', 'new_customer_email', 'new_customer_phone', 'new_customer_second_phone', 'customer_name', 'customer_email', 'customer_phone', 'preferred_contact_method',
            'category_id', 'budget', 'requested_quantity', 'required_date',
            'color_preferences', 'scent_preferences', 'packaging_preferences', 'customer_message'] as $key) {
            $values[$key] = $this->postString($post, $key);
        }
        $values['custom_products'] = $this->safeProducts($post['custom_products'] ?? [], ['working_name','category_id','product_type_id','requested_quantity','color_preferences','scent_preferences','color_choice','scent_choice','color_custom','scent_custom','specifications']);
        $token = FormInput::csrfToken($post);
        $validCsrf = $this->csrf->validate($token);
        $this->csrf->rotate();
        if (!$validCsrf) {
            return ['success' => false, 'errors' => ['form' => 'The request form expired. Review it and try again.'], 'values' => $values];
        }
        if ($payloadError !== null) {
            return ['success' => false, 'errors' => ['form' => $payloadError], 'values' => $values];
        }
        foreach (['existing_customer_name', 'existing_customer_email', 'existing_customer_phone'] as $key) {
            if (!array_key_exists($key, $post)) {
                unset($values[$key]);
            }
        }
        try {
            if ($saveDraft) {
                \App\Services\AdminWorkDrafts::save('request', $administratorId, $values);
                return ['success' => false,'draftSaved' => true,'errors' => [],'values' => $values];
            }
            $result = $this->requests->create($administratorId, $values);
        } catch (DomainException $exception) {
            $result = ['success' => false,'errors' => ['form' => $exception->getMessage()]];
        } catch (Throwable) {
            error_log('An administrator-created custom request could not be saved.');
            $result = ['success' => false, 'errors' => ['form' => 'The custom request could not be created. No data was saved.']];
        }

        return $result + ['values' => $values];
    }

    /** A pricing preview checks CSRF but does not consume the editable form. */
    public function previewConversion(array $post): array
    {
        if (!$this->csrf->validate(FormInput::csrfToken($post))) {
            return ['success' => false,'errors' => ['form' => 'The form expired. Reload before reviewing the quotation.']];
        }
        $id = FormInput::positiveId($post['id'] ?? null);
        if ($id === null) {
            return ['success' => false,'errors' => ['form' => 'Select an available request.']];
        }
        try {
            return $this->conversion->quote($id, CustomRequestPayload::decode($post));
        } catch (Throwable) {
            return ['success' => false,'errors' => ['form' => 'The quotation could not be checked. Please try again.']];
        }
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

    public function processBriefEdit(array $post, int $administratorId): array
    {
        $payloadError = null;
        try {
            $post = CustomRequestPayload::decode($post);
        } catch (DomainException $error) {
            $payloadError = $error->getMessage();
        }
        $requestId = FormInput::positiveId($post['id'] ?? null);
        $values = ['version' => $this->postString($post, 'version'), 'revision_reason' => $this->postString($post, 'revision_reason'),
            'custom_products' => $this->safeProducts($post['custom_products'] ?? [], ['working_name','category_id','product_type_id','requested_quantity','color_choice','scent_choice','color_custom','scent_custom','specifications'])];
        $valid = $this->csrf->validate(FormInput::csrfToken($post));
        $this->csrf->rotate();
        if (!$valid) {
            return ['success' => false,'errors' => ['form' => 'The form expired. Your entries are retained; review and try again.'],'values' => $values,'requestId' => $requestId];
        }
        if ($payloadError !== null || $requestId === null) {
            return ['success' => false,'errors' => ['form' => $payloadError ?? 'Select an available request.'],'values' => $values,'requestId' => $requestId];
        }
        try {
            $result = $this->requests->editBrief($requestId, $administratorId, $values);
        } catch (Throwable) {
            error_log('A custom request brief correction could not be saved.');
            $result = ['success' => false,'errors' => ['form' => 'The correction could not be saved. Your entries are retained. Please try again.']];
        }
        return $result + ['values' => $values,'requestId' => $requestId];
    }


    private function safeProducts(mixed $raw, array $keys): array
    {
        if (!is_array($raw)) {
            return [];
        }
        return array_map(static function ($row) use ($keys): array {
            $safe = [];
            foreach ($keys as $key) {
                $safe[$key] = is_array($row) && is_string($row[$key] ?? null) ? $row[$key] : '';
            }
            if (is_array($row) && array_key_exists('components', $row)) {
                $safe['components'] = is_array($row['components']) ? array_map(static function ($component): array {
                    $child = [];
                    foreach (['source','product_id','quantity','custom_name','color_choice','scent_choice','color_custom','scent_custom','specifications'] as $key) {
                        $child[$key] = is_array($component) && is_string($component[$key] ?? null) ? $component[$key] : '';
                    }
                    return $child;
                }, array_values(array_slice($row['components'], 0, 51))) : null;
            }
            return $safe;
        }, array_values(array_slice($raw, 0, 51)));
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
