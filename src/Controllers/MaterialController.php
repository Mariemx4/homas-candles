<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\FormInput;
use App\Security\CsrfTokenManager;
use App\Services\MaterialService;
use Throwable;

final class MaterialController
{
    public function __construct(
        private readonly MaterialService $materials,
        private readonly CsrfTokenManager $csrf
    ) {
    }

    /**
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
                'filters' => ['search' => null, 'type' => null, 'active' => null, 'tracks_stock' => null, 'stock_status' => null],
                'sort' => 'name', 'direction' => 'asc', 'page' => 1, 'perPage' => MaterialService::PER_PAGE,
                'total' => 0, 'totalPages' => 1, 'queryState' => [], 'returnQuery' => '', 'filtersAdjusted' => false,
                'loadError' => 'Materials are temporarily unavailable. Please try again later.',
                'httpStatus' => 503,
            ];
        }
    }

    /**
     * @return array{detail: array<string, mixed>|null, httpStatus: int, loadError: string|null}
     */
    public function show(mixed $rawId): array
    {
        $id = FormInput::positiveId($rawId);
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


    public function processAction(array $post, int $administratorId): array
    {
        $materialId = FormInput::positiveId($post['id'] ?? null);
        $action = isset($post['action']) && is_string($post['action']) ? $post['action'] : '';
        $values = $post;
        unset($values['csrf_token']);

        $token = FormInput::csrfToken($post);
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


    private function processActivate(?int $materialId, bool $active): array
    {
        if ($materialId === null) {
            return ['success' => false, 'errors' => ['form' => 'The material identifier is invalid.']];
        }

        return $this->materials->toggleActive($materialId, $active);
    }

    /**
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
     * @return array<string, mixed>
     */
    private function result(bool $success, ?int $materialId, string $action, array $errors, array $values): array
    {
        return ['success' => $success, 'id' => $materialId, 'action' => $action, 'errors' => $errors, 'values' => $values];
    }
}
