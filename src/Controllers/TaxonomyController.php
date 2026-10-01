<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Application controller for Taxonomy Controller; it coordinates a use case for the HTTP-facing layer.
 *
 * DATA FLOW: A public entry point calls this controller with request-ready input. The controller delegates rules to services and returns data for a redirect, response, or view.
 */

namespace App\Controllers;

use App\Security\CsrfTokenManager;
use App\Services\TaxonomyService;
use Throwable;

final class TaxonomyController
{
    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
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
        $id = $this->positiveId($rawId);
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

    /**
     * FUNCTION PURPOSE: Handle the save request branch and return a consistent result.
     *
     * @return array<string, mixed>
     */
    public function processSave(string $kind, array $post): array
    {
        $id = $this->positiveId($post['id'] ?? null);
        $values = $post;
        unset($values['csrf_token']);

        $token = isset($post['csrf_token']) && is_string($post['csrf_token']) ? $post['csrf_token'] : null;
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

    /**
     * FUNCTION PURPOSE: Handle the toggle request branch and return a consistent result.
     *
     * @return array<string, mixed>
     */
    public function processToggle(string $kind, array $post): array
    {
        $id = $this->positiveId($post['id'] ?? null);
        $token = isset($post['csrf_token']) && is_string($post['csrf_token']) ? $post['csrf_token'] : null;
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
