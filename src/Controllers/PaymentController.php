<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Application controller for Payment Controller; it coordinates a use case for the HTTP-facing layer.
 *
 * DATA FLOW: A public entry point calls this controller with request-ready input. The controller delegates rules to services and returns data for a redirect, response, or view.
 */

namespace App\Controllers;

use App\Security\CsrfTokenManager;
use App\Services\PaymentService;
use DateTimeImmutable;
use Throwable;

final class PaymentController
{
    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
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
        $orderId = $this->positiveId($query['order_id'] ?? null);
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

    /**
     * FUNCTION PURPOSE: Handle the record request branch and return a consistent result.
     *
     * @return array<string, mixed>
     */
    public function processRecord(array $post, ?array $evidenceFile, int $administratorId): array
    {
        $orderId = $this->positiveId($post['order_id'] ?? null);
        $values = [];
        foreach (['payment_method', 'payment_type', 'amount', 'd17_reference', 'customer_declared_paid_at'] as $key) {
            $values[$key] = $this->postString($post, $key);
        }
        $token = isset($post['csrf_token']) && is_string($post['csrf_token']) ? $post['csrf_token'] : null;
        $validCsrf = $this->csrf->validate($token);
        $this->csrf->rotate();
        if (!$validCsrf) {
            return ['success' => false, 'orderId' => $orderId, 'errors' => ['form' => 'The form expired. Review the order and try again.'], 'values' => $values];
        }
        if ($orderId === null) {
            return ['success' => false, 'orderId' => null, 'errors' => ['form' => 'The order identifier is invalid.'], 'values' => $values];
        }

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

    /**
     * @return array<string, mixed>
     */
    public function showReview(array $query): array
    {
        $paymentId = $this->positiveId($query['id'] ?? null);
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

    /**
     * FUNCTION PURPOSE: Handle the decision request branch and return a consistent result.
     *
     * @return array<string, mixed>
     */
    public function processDecision(array $post, int $administratorId): array
    {
        $paymentId = $this->positiveId($post['id'] ?? null);
        $action = $this->postString($post, 'action');
        $reason = $this->postString($post, 'reason');
        $token = isset($post['csrf_token']) && is_string($post['csrf_token']) ? $post['csrf_token'] : null;
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

    /**
     * FUNCTION PURPOSE: Resolve evidence into the safe concrete value needed by the caller.
     *
     * @return array{allowed: bool, absolutePath?: string, mimeType?: string, filename?: string}
     */
    public function resolveEvidence(array $query): array
    {
        $paymentId = $this->positiveId($query['id'] ?? null);
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

    private function postString(array $post, string $key): string
    {
        return isset($post[$key]) && is_string($post[$key]) ? $post[$key] : '';
    }
}
