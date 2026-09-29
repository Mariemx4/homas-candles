<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Application controller for Material Controller; it coordinates a use case for the HTTP-facing layer.
 *
 * DATA FLOW: A public entry point calls this controller with request-ready input. The controller delegates rules to services and returns data for a redirect, response, or view.
 */

namespace App\Controllers;

use App\Security\CsrfTokenManager;
use App\Services\MaterialService;
use Throwable;

final class MaterialController
{
    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly MaterialService $materials,
        private readonly CsrfTokenManager $csrf
    ) {
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `index` (index) and return its result.
     *
     * @return array<string, mixed>
     */
    public function index(array $query): array
    {
        try {
            return $this->materials->list($query) + ['loadError' => null, 'httpStatus' => 200];
        } catch (Throwable) {
            error_log('The administrator material list could not be loaded.');

            return [
                'materials' => [], 'types' => [],
                'filters' => ['search' => null, 'type' => null, 'active' => null, 'tracks_stock' => null],
                'sort' => 'name', 'direction' => 'asc', 'page' => 1, 'perPage' => MaterialService::PER_PAGE,
                'total' => 0, 'totalPages' => 1, 'queryState' => [], 'returnQuery' => '', 'filtersAdjusted' => false,
                'loadError' => 'Materials are temporarily unavailable. Please try again later.',
                'httpStatus' => 503,
            ];
        }
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `show` (show) and return its result.
     *
     * @return array{detail: array<string, mixed>|null, httpStatus: int, loadError: string|null}
     */
    public function show(mixed $rawId): array
    {
        $id = $this->positiveId($rawId);
        if ($rawId !== null && $rawId !== '' && $id === null) {
            return ['detail' => null, 'httpStatus' => 400, 'loadError' => 'The material identifier is invalid.'];
        }

        try {
            $detail = $this->materials->detail($id);
        } catch (Throwable) {
            error_log('An administrator material record could not be loaded.');

            return ['detail' => null, 'httpStatus' => 503, 'loadError' => 'This material is temporarily unavailable. Please try again later.'];
        }

        return [
            'detail' => $detail,
            'httpStatus' => $detail === null ? 404 : 200,
            'loadError' => $detail === null ? 'The requested material could not be found.' : null,
        ];
    }

    /**
     * FUNCTION PURPOSE: Handle the action request branch and return a consistent result.
     *
     * @return array<string, mixed>
     */
    public function processAction(array $post, int $administratorId): array
    {
        $materialId = $this->positiveId($post['id'] ?? null);
        $action = isset($post['action']) && is_string($post['action']) ? $post['action'] : '';
        $values = $post;
        unset($values['csrf_token']);

        $token = isset($post['csrf_token']) && is_string($post['csrf_token']) ? $post['csrf_token'] : null;
        $validCsrf = $this->csrf->validate($token);
        $this->csrf->rotate();
        if (!$validCsrf) {
            return $this->result(false, $materialId, $action, ['form' => 'The form expired. Review the record and try again.'], $values);
        }

        try {
            // BRANCHING IDIOM: `match` selects exactly one result using strict comparisons and rejects/handles a default explicitly.
            $result = match ($action) {
                'save_core' => $this->materials->save($materialId, $post),
                'record_movement' => $this->processMovement($materialId, $post, $administratorId),
                'activate' => $this->processActivate($materialId, true),
                'deactivate' => $this->processActivate($materialId, false),
                default => ['success' => false, 'errors' => ['form' => 'The requested action is not supported.']],
            };
        } catch (Throwable) {
            error_log('An administrator material action could not be completed.');
            $result = ['success' => false, 'errors' => ['form' => 'This change could not be saved. Please try again.']];
        }

        if ($action === 'save_core' && $result['success'] && $materialId === null) {
            $materialId = $result['id'];
        }

        // `notice` names products a cost change returned to draft (ProductReadinessService), if any.
        return $this->result($result['success'], $materialId, $action, $result['errors'], $values)
            + ['notice' => $result['notice'] ?? null];
    }

    /**
     * FUNCTION PURPOSE: Handle the movement request branch and return a consistent result.
     *
     * @return array<string, mixed>
     */
    private function processMovement(?int $materialId, array $post, int $administratorId): array
    {
        if ($materialId === null) {
            return ['success' => false, 'errors' => ['form' => 'The material identifier is invalid.']];
        }
        $movementType = isset($post['movement_type']) && is_string($post['movement_type']) ? strtoupper($post['movement_type']) : '';
        $quantity = isset($post['quantity_change']) && is_string($post['quantity_change']) ? $post['quantity_change'] : '';
        $note = isset($post['movement_note']) && is_string($post['movement_note']) ? $post['movement_note'] : '';

        return $this->materials->recordMovement($materialId, $administratorId, $movementType, $quantity, $note);
    }

    /**
     * FUNCTION PURPOSE: Handle the activate request branch and return a consistent result.
     *
     * @return array<string, mixed>
     */
    private function processActivate(?int $materialId, bool $active): array
    {
        if ($materialId === null) {
            return ['success' => false, 'errors' => ['form' => 'The material identifier is invalid.']];
        }

        return $this->materials->toggleActive($materialId, $active);
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `movementHistory` (movement history) and return its result.
     *
     * @return array<string, mixed>
     */
    public function movementHistory(array $query): array
    {
        try {
            return $this->materials->movementHistory($query) + ['loadError' => null, 'httpStatus' => 200];
        } catch (Throwable) {
            error_log('The administrator material movement history could not be loaded.');

            return [
                'movements' => [],
                'filters' => ['material' => null, 'type' => null, 'order' => null, 'administrator' => null, 'date_from' => null, 'date_to' => null],
                'page' => 1, 'perPage' => MaterialService::PER_PAGE, 'total' => 0, 'totalPages' => 1,
                'queryState' => [], 'filtersAdjusted' => false,
                'loadError' => 'Movement history is temporarily unavailable. Please try again later.',
                'httpStatus' => 503,
            ];
        }
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

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `result` (result) and return its result.
     *
     * @return array<string, mixed>
     */
    private function result(bool $success, ?int $materialId, string $action, array $errors, array $values): array
    {
        return ['success' => $success, 'id' => $materialId, 'action' => $action, 'errors' => $errors, 'values' => $values];
    }
}
