<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\FormInput;
use App\Services\PricingService;
use App\Security\CsrfTokenManager;
use Throwable;

final class PricingConfigurationController
{
    public function __construct(private readonly PricingService $pricing, private readonly CsrfTokenManager $csrf)
    {
    }
    public function promotions(): array
    {
        return $this->pricing->promotions();
    }
    public function save(array $post): array
    {
        $values = [];
        foreach (['code','discount_type','discount_value','is_active','valid_from','valid_until','minimum_subtotal'] as $key) {
            $values[$key] = is_string($post[$key] ?? null) ? $post[$key] : '';
        }
        $valid = $this->csrf->validate(FormInput::csrfToken($post));
        $this->csrf->rotate();
        if (!$valid) {
            return ['success' => false, 'errors' => ['form' => 'The form expired. Review it and try again.'], 'values' => $values];
        }
        try {
            return $this->pricing->savePromotion($values) + ['values' => $values];
        } catch (Throwable) {
            return ['success' => false, 'errors' => ['form' => 'This promotion could not be saved. Please try again.'], 'values' => $values];
        }
    }
}
