<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\FormInput;
use App\Security\CsrfTokenManager;
use App\Services\TaxonomyService;
use Throwable;

final class TaxonomyController
{
    public function __construct(
        private readonly TaxonomyService $taxonomy,
        private readonly CsrfTokenManager $csrf
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function index(string $kind, array $query): array
    {
        try {
            return $this->taxonomy->list($kind, $query) + ['loadError' => null, 'httpStatus' => 200];
        } catch (Throwable) {
            error_log('The administrator ' . $kind . ' list could not be loaded.');

            return [
                'items' => [],
                'filters' => ['search' => null, 'active' => null],
                'page' => 1,
                'perPage' => TaxonomyService::PER_PAGE,
                'total' => 0,
                'totalPages' => 1,
                'queryState' => [],
                'returnQuery' => '',
                'filtersAdjusted' => false,
                'loadError' => ucfirst($kind) . ' are temporarily unavailable. Please try again later.',
                'httpStatus' => 503,
            ];
        }
    }

    /**
     * @return array{item: array<string, mixed>|null, materialOptions: list<array<string,mixed>>, httpStatus: int, loadError: string|null}
     */
    public function show(string $kind, mixed $rawId): array
    {
        $id = FormInput::positiveId($rawId);
        if ($rawId !== null && $rawId !== '' && $id === null) {
            return ['item' => null, 'materialOptions' => [], 'httpStatus' => 400, 'loadError' => 'The identifier is invalid.'];
        }

        try {
            $item = $this->taxonomy->detail($kind, $id);
            $materialOptions = $kind === 'scents' ? $this->taxonomy->fragranceMaterialOptions() : [];
        } catch (Throwable) {
            error_log('An administrator ' . $kind . ' record could not be loaded.');

            return ['item' => null, 'materialOptions' => [], 'httpStatus' => 503, 'loadError' => 'This record is temporarily unavailable. Please try again later.'];
        }

        if ($item === []) {
            return ['item' => null, 'materialOptions' => [], 'httpStatus' => 404, 'loadError' => 'The requested record could not be found.'];
        }

        return ['item' => $item, 'materialOptions' => $materialOptions, 'httpStatus' => 200, 'loadError' => null];
    }


    public function processSave(string $kind, array $post): array
    {
        $id = FormInput::positiveId($post['id'] ?? null);
        $values = $post;
        unset($values['csrf_token']);

        $token = FormInput::csrfToken($post);
        $validCsrf = $this->csrf->validate($token);
        $this->csrf->rotate();
        if (!$validCsrf) {
            return ['success' => false, 'id' => $id, 'errors' => ['form' => 'The form expired. Review the record and try again.'], 'values' => $values];
        }

        try {
            $result = $this->taxonomy->save($kind, $id, $post);
        } catch (Throwable) {
            error_log('An administrator ' . $kind . ' record could not be saved.');
            $result = ['success' => false, 'errors' => ['form' => 'This record could not be saved. Please try again.']];
        }

        return $result + ['id' => $id, 'values' => $values];
    }


    public function processToggle(string $kind, array $post): array
    {
        $id = FormInput::positiveId($post['id'] ?? null);
        $token = FormInput::csrfToken($post);
        $validCsrf = $this->csrf->validate($token);
        $this->csrf->rotate();
        if (!$validCsrf) {
            return ['success' => false, 'id' => $id, 'errors' => ['form' => 'The form expired. Review the record and try again.']];
        }
        if ($id === null) {
            return ['success' => false, 'id' => null, 'errors' => ['form' => 'The identifier is invalid.']];
        }

        $active = ($post['active'] ?? '') === '1';
        try {
            $result = $this->taxonomy->toggleActive($kind, $id, $active);
        } catch (Throwable) {
            error_log('An administrator ' . $kind . ' active state could not be changed.');
            $result = ['success' => false, 'errors' => ['form' => 'This change could not be saved. Please try again.']];
        }

        return $result + ['id' => $id];
    }


}
