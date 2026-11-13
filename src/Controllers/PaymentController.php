<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\FormInput;
use App\Security\CsrfTokenManager;
use App\Services\PaymentService;
use DateTimeImmutable;
use Throwable;

final class PaymentController
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly CsrfTokenManager $csrf
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function index(array $query): array
    {
        try {
            return $this->payments->list($query) + ['loadError' => null, 'httpStatus' => 200];
        } catch (Throwable) {
            error_log('The administrator payment queue could not be loaded.');

            return [
                'payments' => [],
                'filters' => ['search' => null, 'status' => null, 'method' => null, 'date_from' => null, 'date_to' => null],
                'page' => 1,
                'perPage' => PaymentService::PER_PAGE,
                'total' => 0,
                'totalPages' => 1,
                'queryState' => [],
                'returnQuery' => '',
                'filtersAdjusted' => false,
                'overview' => null,
                'hasActiveFilters' => false,
                'loadError' => 'Payments are temporarily unavailable. Please try again later.',
                'httpStatus' => 503,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function showOrder(array $query): array
    {
        $orderId = FormInput::positiveId($query['order_id'] ?? null);
        if ($orderId === null) {
            return ['detail' => null, 'orderId' => null, 'httpStatus' => 400, 'loadError' => 'The order identifier is invalid.'];
        }

        try {
            $detail = $this->payments->orderSummary($orderId);
        } catch (Throwable) {
            error_log('The administrator order payment summary could not be loaded.');

            return ['detail' => null, 'orderId' => $orderId, 'httpStatus' => 503, 'loadError' => 'Payment details are temporarily unavailable. Please try again later.'];
        }

        return [
            'detail' => $detail,
            'orderId' => $orderId,
            'httpStatus' => $detail === null ? 404 : 200,
            'loadError' => $detail === null ? 'The requested order could not be found.' : null,
        ];
    }


    public function processRecord(array $post, ?array $evidenceFile, int $administratorId): array
    {
        $orderId = FormInput::positiveId($post['order_id'] ?? null);
        $values = [];
        foreach (['payment_method', 'payment_type', 'amount', 'd17_reference', 'customer_declared_paid_at'] as $key) {
            $values[$key] = $this->postString($post, $key);
        }
        $token = FormInput::csrfToken($post);
        $validCsrf = $this->csrf->validate($token);
        $this->csrf->rotate();
        if (!$validCsrf) {
            return ['success' => false, 'orderId' => $orderId, 'errors' => ['form' => 'The form expired. Review the order and try again.'], 'values' => $values];
        }
        if ($orderId === null) {
            return ['success' => false, 'orderId' => null, 'errors' => ['form' => 'The order identifier is invalid.'], 'values' => $values];
        }

        if ($administratorId < 1 || (strtoupper(trim($values['payment_method'])) === 'CASH_ON_DELIVERY' && $this->postString($post, 'received_confirmation') !== '1')) {
            return ['success' => false, 'orderId' => $orderId, 'errors' => ['form' => 'Confirm that the payment was actually received before recording it.'], 'values' => $values];
        }
        $post['request_key'] = hash('sha256', $orderId . ':' . $token);

        try {
            $result = $this->payments->record(
                $post + ['order_id' => (string) $orderId],
                $evidenceFile,
                $administratorId,
                new DateTimeImmutable()
            );
        } catch (Throwable) {
            error_log('An administrator payment could not be recorded.');
            $result = ['success' => false, 'errors' => ['form' => 'The payment could not be recorded. Please try again.']];
        }

        return $result + ['orderId' => $orderId, 'values' => $values];
    }

    public function showCustomerOrder(mixed $rawId, ?int $customerId, array $sessionOrderIds): array
    {
        $id = FormInput::positiveId($rawId);
        try {
            $detail = $id === null ? null : $this->payments->customerSummary($id, $customerId, $sessionOrderIds);
            return ['detail' => $detail, 'httpStatus' => $detail === null ? 404 : 200];
        } catch (Throwable) {
            error_log('Customer payment details could not be loaded.');
            return ['detail' => null, 'httpStatus' => 503];
        }
    }

    public function processCustomerEvidence(array $post, ?array $file, ?int $customerId, array $sessionOrderIds): array
    {
        $token = FormInput::csrfToken($post);
        $valid = $this->csrf->validate($token);
        $this->csrf->rotate();
        $orderId = FormInput::positiveId($post['order_id'] ?? null);
        if (!$valid || $orderId === null) {
            return ['success' => false, 'errors' => ['form' => 'The form expired. Reload and try again.']];
        }
        try {
            return $this->payments->submitCustomerEvidence($orderId, $file, $customerId, $sessionOrderIds, hash('sha256', $orderId . ':' . $token));
        } catch (Throwable) {
            error_log('Customer payment evidence submission failed.');
            return ['success' => false, 'errors' => ['form' => 'Payment proof could not be submitted. Please try again.']];
        }
    }

    public function resolveCustomerEvidence(mixed $rawPaymentId, int $orderId, ?int $customerId, array $sessionOrderIds): array
    {
        $id = FormInput::positiveId($rawPaymentId);
        if ($id === null) {
            return ['allowed' => false];
        }
        try {
            if ($this->payments->customerSummary($orderId, $customerId, $sessionOrderIds) === null) {
                return ['allowed' => false];
            }
            $resolved = $this->payments->resolveEvidence($id);
            if ($resolved === null || $resolved['orderId'] !== $orderId) {
                return ['allowed' => false];
            }
            return ['allowed' => true, 'absolutePath' => $resolved['path'], 'mimeType' => $this->payments->evidenceMimeType($resolved['path'])];
        } catch (Throwable) {
            error_log('Customer payment proof could not be resolved.');
            return ['allowed' => false];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function showReview(array $query): array
    {
        $paymentId = FormInput::positiveId($query['id'] ?? null);
        $action = isset($query['action']) && is_string($query['action']) ? $query['action'] : '';
        if ($paymentId === null) {
            return ['payment' => null, 'paymentId' => null, 'action' => $action, 'httpStatus' => 400, 'loadError' => 'The payment identifier is invalid.'];
        }

        try {
            $payment = $this->payments->confirmation($paymentId, $action);
        } catch (Throwable) {
            error_log('An administrator payment review could not be loaded.');

            return ['payment' => null, 'paymentId' => $paymentId, 'action' => $action, 'httpStatus' => 503, 'loadError' => 'The payment is temporarily unavailable. Please try again later.'];
        }

        return [
            'payment' => $payment,
            'paymentId' => $paymentId,
            'action' => $action,
            'httpStatus' => $payment === null ? 404 : 200,
            'loadError' => $payment === null ? 'The requested payment could not be found.' : null,
        ];
    }


    public function processDecision(array $post, int $administratorId): array
    {
        $paymentId = FormInput::positiveId($post['id'] ?? null);
        $action = $this->postString($post, 'action');
        $reason = $this->postString($post, 'reason');
        $token = FormInput::csrfToken($post);
        $validCsrf = $this->csrf->validate($token);
        $this->csrf->rotate();
        if (!$validCsrf) {
            return ['success' => false, 'paymentId' => $paymentId, 'action' => $action, 'errors' => ['form' => 'The form expired. Review the payment and try again.'], 'values' => ['reason' => $reason]];
        }
        if ($paymentId === null) {
            return ['success' => false, 'paymentId' => null, 'action' => $action, 'errors' => ['form' => 'The payment identifier is invalid.'], 'values' => ['reason' => $reason]];
        }

        try {
            if ($action === 'verify') {
                $result = $this->payments->verify($paymentId, $administratorId, new DateTimeImmutable());
            } elseif ($action === 'reject') {
                $result = $this->payments->reject($paymentId, $administratorId, $reason);
            } else {
                return ['success' => false, 'paymentId' => $paymentId, 'action' => $action, 'errors' => ['form' => 'The requested decision is not supported.'], 'values' => ['reason' => $reason]];
            }
        } catch (Throwable) {
            error_log('An administrator payment decision could not be completed.');
            $result = ['success' => false, 'errors' => ['form' => 'The decision could not be completed. Please try again.']];
        }

        return $result + ['paymentId' => $paymentId, 'action' => $action, 'values' => ['reason' => $reason]];
    }


    public function resolveEvidence(array $query): array
    {
        $paymentId = FormInput::positiveId($query['id'] ?? null);
        if ($paymentId === null) {
            return ['allowed' => false];
        }

        try {
            $resolved = $this->payments->resolveEvidence($paymentId);
        } catch (Throwable) {
            error_log('Administrator payment evidence could not be resolved.');

            return ['allowed' => false];
        }

        if ($resolved === null) {
            return ['allowed' => false];
        }

        return [
            'allowed' => true,
            'absolutePath' => $resolved['path'],
            'mimeType' => $this->payments->evidenceMimeType($resolved['path']),
            'filename' => 'payment-' . $paymentId . '-evidence' . $this->extensionOf($resolved['path']),
        ];
    }

    private function extensionOf(string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return $extension === '' ? '' : '.' . $extension;
    }


    private function postString(array $post, string $key): string
    {
        return isset($post[$key]) && is_string($post[$key]) ? $post[$key] : '';
    }
}
