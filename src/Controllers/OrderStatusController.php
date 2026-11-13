<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\FormInput;
use App\Security\CsrfTokenManager;
use App\Services\OrderService;
use App\Services\OrderStatusService;
use Throwable;

final class OrderStatusController
{
    public function __construct(
        private readonly OrderStatusService $statuses,
        private readonly OrderService $orders,
        private readonly CsrfTokenManager $csrf
    ) {
    }


    public function summary(array $order): array
    {
        return $this->statuses->summary($order);
    }

    public function selectionOptions(array $order): array
    {
        return $this->statuses->selectionOptions($order);
    }


    public function confirmation(array $order, mixed $proposedStatus): array
    {
        $summary = $this->statuses->summary($order);
        $proposed = is_string($proposedStatus) ? strtoupper(trim($proposedStatus)) : '';
        if ($proposed === '' || $proposed !== $summary['nextStatus']) {
            return $summary + [
                'validProposal' => false,
                'proposalError' => 'That status transition is not available for this order.',
            ];
        }

        return $summary + ['validProposal' => true, 'proposalError' => null];
    }


    public function process(array $post, int $administratorId, bool $directSelection = false): array
    {
        $orderId = $this->orderId($post['id'] ?? null);
        $newStatus = isset($post['new_status']) && is_string($post['new_status'])
            ? strtoupper(trim($post['new_status']))
            : '';
        $rawReturn = isset($post['return']) && is_string($post['return']) ? $post['return'] : '';
        $returnQuery = $this->orders->normalizedReturnQuery($rawReturn);
        $invalidNote = isset($post['note']) && !is_string($post['note']);
        $note = isset($post['note']) && is_string($post['note']) ? $post['note'] : '';

        $token = FormInput::csrfToken($post);
        $validCsrf = $this->csrf->validate($token);
        $this->csrf->rotate();
        if (!$validCsrf) {
            return $this->result(false, $orderId, $newStatus, $returnQuery, $note, [
                'form' => 'The transition form expired. Review the order and try again.',
            ]);
        }
        if ($orderId === null) {
            return $this->result(false, null, $newStatus, $returnQuery, $note, [
                'form' => 'The order identifier is invalid.',
            ]);
        }
        if ($invalidNote) {
            return $this->result(false, $orderId, $newStatus, $returnQuery, '', [
                'note' => 'The administrator note is invalid.',
            ]);
        }
        if ($newStatus === '') {
            return $this->result(false, $orderId, '', $returnQuery, $note, [
                'new_status' => 'Select a valid order status.',
            ]);
        }

        try {
            $transition = $directSelection
                ? $this->statuses->selectStatus(
                    $orderId,
                    $administratorId,
                    $newStatus,
                    isset($post['expected_status']) && is_string($post['expected_status']) ? $post['expected_status'] : ''
                )
                : $this->statuses->transition($orderId, $administratorId, $newStatus, $note);
        } catch (Throwable) {
            error_log('The administrator order transition could not be completed.');
            $transition = [
                'success' => false,
                'errors' => ['form' => 'The status transition is temporarily unavailable. Please try again.'],
            ];
        }

        return $this->result(
            $transition['success'],
            $orderId,
            $newStatus,
            $returnQuery,
            $note,
            $transition['errors'],
            $transition['orderNumber'] ?? null
        );
    }

    private function orderId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,18}$/', $value) !== 1) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : (int) $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function result(
        bool $success,
        ?int $orderId,
        string $newStatus,
        string $returnQuery,
        string $note,
        array $errors,
        ?string $orderNumber = null
    ): array {
        return [
            'success' => $success,
            'orderId' => $orderId,
            'newStatus' => $newStatus,
            'returnQuery' => $returnQuery,
            'values' => ['note' => substr($note, 0, 500)],
            'errors' => $errors,
            'orderNumber' => $orderNumber,
        ];
    }
}
