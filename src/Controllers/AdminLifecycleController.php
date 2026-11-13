<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\FormInput;
use App\Services\AdminLifecycleService;
use App\Repositories\AdminLifecycleRepository;
use App\Security\CsrfTokenManager;

final class AdminLifecycleController
{
    public function __construct(private readonly AdminLifecycleService $service, private readonly CsrfTokenManager $csrf)
    {
    }
    public static function identity(array $input): ?array
    {
        $kind = is_string($input['kind'] ?? null) ? $input['kind'] : '';
        $id = is_string($input['id'] ?? null) ? filter_var($input['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        return isset(AdminLifecycleRepository::TABLES[$kind]) && $id !== false ? [$kind,$id] : null;
    }
    public function process(array $input, int $actor): array
    {
        $valid = $this->csrf->validate(FormInput::csrfToken($input));
        $this->csrf->rotate();
        if (!$valid) {
            return ['success' => false,'errors' => ['form' => 'The security token expired. Reload and try again.']];
        }
        $identity = self::identity($input);
        if (!$identity) {
            return ['success' => false,'errors' => ['form' => 'Select a valid record.']];
        }
        return $this->service->mutate(...[...$identity,$actor,is_string($input['action'] ?? null) ? $input['action'] : '']);
    }
}
