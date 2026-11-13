<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\FormInput;
use App\Security\CsrfTokenManager;
use App\Services\ManualOrderService;
use Throwable;

final class ManualOrderController
{
    public function preview(array $post): array
    {
        if (!$this->csrf->validate(FormInput::csrfToken($post))) {
            return ['success' => false, 'error' => 'The order form expired. Reload it and try again.'];
        }
        try {
            return $this->orders->preview($this->safeValues($post));
        } catch (Throwable) {
            return ['success' => false, 'error' => 'Pricing is temporarily unavailable. Please try again.'];
        }
    }

    public function review(array $post): array
    {
        $pricing = $this->preview($post);
        $this->csrf->rotate();
        return ['errors' => $pricing['success'] ? [] : ['pricing' => $pricing['error']], 'values' => $this->safeValues($post)];
    }
    public function __construct(
        private readonly ManualOrderService $orders,
        private readonly CsrfTokenManager $csrf
    ) {
    }

    /** @return array<string, mixed> */
    public function process(array $post, int $administratorId, bool $saveDraft = false): array
    {
        // Only a small allow-list is retained for flash redisplay. In particular,
        // the CSRF token is never copied into session flash data.
        $values = $this->safeValues($post);
        $token = FormInput::csrfToken($post);
        $validCsrf = $this->csrf->validate($token);
        $this->csrf->rotate();
        if (!$validCsrf) {
            return ['success' => false, 'errors' => ['form' => 'The order form expired. Review it and try again.'], 'values' => $values];
        }
        try {
            if ($saveDraft) {
                \App\Services\AdminWorkDrafts::save('order', $administratorId, $values);
                return ['success' => false,'draftSaved' => true,'errors' => [],'values' => $values];
            }
            $result = $this->orders->create($administratorId, $values);
        } catch (\DomainException $exception) {
            $result = ['success' => false,'errors' => ['form' => $exception->getMessage()]];
        } catch (Throwable) {
            error_log('An administrator manual order could not be created.');
            $result = ['success' => false, 'errors' => ['form' => 'The order could not be created. No data was saved.']];
        }
        return $result + ['values' => $values];
    }


    public function formOptions(): array
    {
        try {
            return $this->orders->formOptions();
        } catch (Throwable) {
            error_log('The manual order catalogue could not be loaded.');

            return ['products' => [], 'materials' => []];
        }
    }

    /** @return array<string, mixed> */
    private function safeValues(array $post): array
    {
        $values = [];
        foreach (['promo_code', 'manual_discount_type', 'manual_discount_value', 'manual_discount_reason', 'customer_mode', 'customer_id', 'create_customer', 'customer_second_phone', 'customer_name', 'customer_email', 'customer_phone', 'delivery_method', 'required_date', 'delivery_fee', 'street_address', 'delegation', 'governorate', 'postal_code', 'customer_notes', 'administrator_notes', 'copy_account_address', 'account_street_address', 'account_delegation', 'account_governorate', 'account_postal_code',
            'existing_customer_name', 'existing_customer_email', 'existing_customer_phone', 'existing_customer_notes',
            'guest_customer_name', 'guest_customer_email', 'guest_customer_phone', 'guest_customer_notes',
            'new_customer_name', 'new_customer_email', 'new_customer_phone', 'new_customer_second_phone', 'new_customer_notes'] as $key) {
            $values[$key] = isset($post[$key]) && is_string($post[$key]) ? $post[$key] : '';
        }
        $values['items'] = [];
        $values['catalogue_only'] = true;
        foreach (['existing_customer_name', 'existing_customer_email', 'existing_customer_phone'] as $field) {
            if (!array_key_exists($field, $post)) {
                unset($values[$field]);
            }
        }
        if (isset($post['items']) && is_array($post['items'])) {
            // Read at most 51 entries: the service can then report that the
            // documented limit of 50 was exceeded without accepting an
            // unbounded attacker-controlled array into session flash data.
            foreach (array_slice($post['items'], 0, 51) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $safeItem = [];
                foreach (['product_id', 'color_id', 'scent_id', 'quantity', 'customization_notes'] as $key) {
                    $safeItem[$key] = isset($item[$key]) && is_string($item[$key]) ? $item[$key] : '';
                }
                $values['items'][] = $safeItem;
            }
        }
        return $values;
    }
}
