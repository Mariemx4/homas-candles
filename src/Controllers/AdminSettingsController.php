<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Application controller for Admin Settings Controller; it coordinates a use case for the HTTP-facing layer.
 *
 * DATA FLOW: A public entry point calls this controller with request-ready input. The controller delegates rules to services and returns data for a redirect, response, or view.
 */

namespace App\Controllers;

use App\Services\AdminSettingsService;

final class AdminSettingsController
{
    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
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
