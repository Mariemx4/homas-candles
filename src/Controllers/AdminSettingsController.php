<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AdminSettingsService;

final class AdminSettingsController
{
    public function __construct(private readonly AdminSettingsService $settings)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function index(): array
    {
        return $this->settings->capabilities();
    }
}
