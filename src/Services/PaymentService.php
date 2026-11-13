<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\TransactionManager;
use App\Repositories\PaymentRepository;
use DateTimeImmutable;
use DomainException;

final class PaymentService
{
    public const METHODS = ['D17', 'CASH_ON_DELIVERY'];
    public const TYPES = ['DEPOSIT', 'BALANCE', 'FULL_PAYMENT'];
    public const STATUSES = ['PENDING_VERIFICATION', 'VERIFIED', 'REJECTED'];
    public const PER_PAGE = 20;

    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly TransactionManager $transactions,
        private readonly PaymentEvidenceStorage $evidence,
        private readonly array $d17Settings = []
    ) {
    }

    /** The filtered, paginated payments list with each payment's expected amount and the overview counts. */
    public function list(array $query): array
    {
        $normalized = PaymentListFilters::normalize($query);
        $filters = $normalized['filters'];
        $total = $this->payments->countForList($filters);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($normalized['page'], $totalPages);
        $rows = $total === 0 ? [] : $this->payments->findForList(
            $filters,
            ($page - 1) * self::PER_PAGE,
            self::PER_PAGE
        );
        $state = PaymentListFilters::queryState($normalized, $page);
        foreach ($rows as &$row) {
            $row['expected_amount'] = Money::remainingAmount((string) ($row['payment_type'] === 'DEPOSIT' ? $row['required_deposit_amount'] : $row['total_amount']), (string) $row['verified_amount']);
        }
        unset($row);

        return [
            'payments' => $rows,
            'filters' => $filters,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $total,
            'totalPages' => $totalPages,
            'queryState' => $state,
            'returnQuery' => http_build_query($state),
            'filtersAdjusted' => $normalized['adjusted'],
            'overview' => $this->payments->overview(),
            'hasActiveFilters' => count(array_filter($filters, static fn (mixed $value): bool => $value !== null && $value !== '')) > 0,
        ];
    }

    /**
     * Payment totals for one order: verified, pending and rejected amounts, the
     * remaining balance, any credit, how much can still be recorded, the status
     * label and the next D17 amount. A cancelled order owes nothing; what was paid
     * becomes credit.
     *
     * @return array{order: array<string, mixed>, payments: list<array<string, mixed>>}|null
     */
    public function orderSummary(int $orderId): ?array
    {
        $summary = $this->payments->findSummaryForOrder($orderId);
        if ($summary === null) {
            return null;
        }
        foreach (['verified_amount', 'pending_amount', 'rejected_amount'] as $field) {
            $summary[$field] = Money::formatMillis(Money::storedMillis((string) $summary[$field]));
        }
        $summary['remaining_balance'] = Money::remainingAmount(
            (string) $summary['total_amount'],
            (string) $summary['verified_amount']
        );
        $summary['credit_balance'] = Money::remainingAmount((string) $summary['verified_amount'], (string) $summary['total_amount']);
        $summary['recordable_amount'] = Money::formatMillis(max(
            0,
            Money::storedMillis($summary['total_amount']) - Money::storedMillis($summary['verified_amount']) - Money::storedMillis($summary['pending_amount'])
        ));
        $summary['payment_status_label'] = self::balanceLabel($summary['total_amount'], $summary['verified_amount'], $summary['pending_amount']);
        if ($summary['order_status'] === 'CANCELLED') {
            $summary['remaining_balance'] = $summary['recordable_amount'] = '0.000';
            $summary['credit_balance'] = $summary['verified_amount'];
        }
        $summary['next_d17_amount'] = $this->expectedD17Amount($summary);

        $payments = $this->payments->findPaymentsForOrder($orderId);
        $summary['payment_methods_label'] = self::recordedMethods($payments);
        return [
            'order' => $summary,
            'payments' => $payments,
        ];
    }

    /** Readable list of the payment methods used on an order, e.g. 'D17, Pay on delivery / pickup'. */
    public static function recordedMethods(array $payments): string
    {
        $methods = array_unique(array_column($payments, 'payment_method'));
        return $methods === [] ? 'No payments recorded' : implode(', ', array_map(
            static fn (string $method): string => $method === 'CASH_ON_DELIVERY' ? 'Pay on delivery / pickup' : $method,
            $methods
        ));
    }

    /**
     * The payment status shown for an order: Credit/refund due, Paid, Partially paid, Pending verification or Unpaid.
     *
     * @param string|bool $pending the pending amount, or whether anything is pending
     */
    public static function balanceLabel(string $total, string $verified, string|bool $pending): string
    {
        $totalMillis = Money::parseMillis($total) ?? 0;
        $paidMillis = Money::parseMillis($verified) ?? 0;
        return match (true) {
            $paidMillis > $totalMillis => 'Credit/refund due',
            $paidMillis === $totalMillis => 'Paid',
            $paidMillis > 0 => 'Partially paid',
            (is_bool($pending) ? $pending : (Money::parseMillis($pending) ?? 0) > 0) => 'Pending verification',
            default => 'Unpaid',
        };
    }

    private function expectedD17Amount(array $summary): string
    {
        if (($summary['order_status'] ?? '') === 'CANCELLED') {
            return '0.000';
        }
        if (Money::storedMillis((string) $summary['pending_amount']) > 0) {
            return '0.000';
        }
        $depositDue = max(0, Money::storedMillis((string) $summary['required_deposit_amount']) - Money::storedMillis((string) $summary['verified_amount']));
        $remaining = max(0, Money::storedMillis((string) $summary['total_amount']) - Money::storedMillis((string) $summary['verified_amount']));
        return Money::formatMillis(min($remaining, $depositDue > 0 ? $depositDue : $remaining));
    }

    /**
     * orderSummary() for a customer, plus the shop's D17 details, or null when the
     * customer (or this browser session, for guest orders) may not see the order.
     */
    public function customerSummary(int $orderId, ?int $customerId, array $sessionOrderIds): ?array
    {
        if (!$this->payments->customerCanAccess($orderId, $customerId, $sessionOrderIds)) {
            return null;
        }
        $detail = $this->orderSummary($orderId);
        return $detail === null ? null : $detail + ['d17' => $this->d17Settings];
    }

    /** Customers submit proof for the server's current deposit/balance, never a client price. */
    public function submitCustomerEvidence(int $orderId, ?array $file, ?int $customerId, array $sessionOrderIds, string $requestKey): array
    {
        $detail = $this->customerSummary($orderId, $customerId, $sessionOrderIds);
        if ($detail === null) {
            return ['success' => false, 'errors' => ['form' => 'Order not found.']];
        }
        if (!($this->d17Settings['available'] ?? false)) {
            return ['success' => false, 'errors' => ['form' => 'D17 payment details are not configured. Please contact HOM’AS.']];
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['success' => false, 'errors' => ['evidence' => 'Upload your payment proof.']];
        }
        $order = $detail['order'];
        $depositDue = Money::storedMillis((string) $order['required_deposit_amount']) > Money::storedMillis((string) $order['verified_amount']);
        return $this->record([
            'order_id' => (string) $orderId, 'payment_method' => 'D17',
            'payment_type' => $depositDue ? 'DEPOSIT' : (Money::storedMillis((string) $order['verified_amount']) > 0 ? 'BALANCE' : 'FULL_PAYMENT'),
            'amount' => $order['next_d17_amount'], 'request_key' => $requestKey,
        ], $file, 0, new DateTimeImmutable(), ['customerId' => $customerId, 'sessionOrderIds' => $sessionOrderIds]);
    }

    /**
     * Records a payment: cash by staff (verified at once) or D17 with proof (pending review).
     *
     * The proof file is stored first and deleted again if saving fails. Under the
     * order lock the amount must fit what is still unpaid once pending payments are
     * verified; a customer must submit exactly the amount due. A repeated
     * request_key or D17 reference is refused.
     *
     * @param array<string, mixed>|null $evidenceFile the uploaded file ($_FILES entry), required for D17
     * @param int $administratorId 0 for customer submissions
     * @param array{customerId: ?int, sessionOrderIds: list<int>}|null $customerAccess set for customer submissions
     * @return array{success: bool, errors: array<string, string>, paymentId?: int}
     */
    public function record(array $input, ?array $evidenceFile, int $administratorId, DateTimeImmutable $now, ?array $customerAccess = null): array
    {
        if ($customerAccess === null && $administratorId < 1) {
            return ['success' => false, 'errors' => ['form' => 'An administrator is required.']];
        }
        if ($customerAccess !== null && ($input['payment_method'] ?? null) !== 'D17') {
            return ['success' => false, 'errors' => ['form' => 'Customers can only submit D17 evidence.']];
        }
        [$values, $errors] = PaymentRecordValidator::validate($input, $now);
        if ($errors !== []) {
            return ['success' => false, 'errors' => $errors];
        }

        if ($values['payment_method'] === 'D17' && $values['d17_reference'] !== null
            && $this->payments->d17ReferenceExists($values['d17_reference'])
        ) {
            return ['success' => false, 'errors' => ['d17_reference' => 'This D17 reference has already been recorded.']];
        }

        $storedEvidence = null;
        if ($values['payment_method'] === 'D17') {
            $stored = $this->evidence->store($evidenceFile);
            if (!$stored['success']) {
                return ['success' => false, 'errors' => ['evidence' => (string) $stored['error']]];
            }
            $storedEvidence = $stored['path'];
        }

        try {
            $paymentId = $this->transactions->run(function () use ($values, $storedEvidence, $administratorId, $now, $customerAccess): int {
                $order = $this->payments->lockOrder($values['order_id']);
                if ($order === null || $order['deleted_at'] !== null) {
                    throw new DomainException('The selected order could not be found.');
                }
                if ($order['order_status'] === 'CANCELLED') {
                    throw new DomainException('This order is cancelled. Contact the administrator about any recorded payments.');
                }

                $sums = $this->payments->lockSumsForOrder($values['order_id']);
                if ($customerAccess !== null) {
                    if (!$this->payments->customerCanAccess($values['order_id'], $customerAccess['customerId'], $customerAccess['sessionOrderIds'])) {
                        throw new DomainException('Order not found.');
                    }
                    $expected = $this->expectedD17Amount($order + ['verified_amount' => $sums['verified'], 'pending_amount' => $sums['pending']]);
                    if ($values['amount_millis'] !== Money::storedMillis($expected) || $storedEvidence === null) {
                        throw new DomainException('The amount due changed or a payment is awaiting review. Refresh before submitting new proof.');
                    }
                }
                $remainingMillis = Money::storedMillis((string) $order['total_amount'])
                    - Money::storedMillis($sums['verified'])
                    - Money::storedMillis($sums['pending']);
                if ($values['amount_millis'] > max(0, $remainingMillis)) {
                    throw new DomainException('This amount would exceed the order total once pending payments are verified.');
                }

                $isCash = $values['payment_method'] === 'CASH_ON_DELIVERY';

                return $this->payments->insert([
                    'order_id' => $values['order_id'],
                    'payment_method' => $values['payment_method'],
                    'payment_type' => $values['payment_type'],
                    'payment_status' => $isCash ? 'VERIFIED' : 'PENDING_VERIFICATION',
                    'amount' => Money::formatMillis($values['amount_millis']),
                    'd17_reference' => $values['payment_method'] === 'D17' ? $values['d17_reference'] : null,
                    'payment_proof_path' => $values['payment_method'] === 'D17' ? $storedEvidence : null,
                    'customer_declared_paid_at' => $values['payment_method'] === 'D17'
                        ? $values['customer_declared_paid_at']
                        : null,
                    'verified_at' => $isCash ? $now->format('Y-m-d H:i:s') : null,
                    'verified_by_administrator_id' => $isCash ? $administratorId : null,
                    'request_key' => $values['request_key'],
                ]);
            });
        } catch (DomainException $exception) {
            $this->evidence->discard($storedEvidence);

            return ['success' => false, 'errors' => ['form' => $exception->getMessage()]];
        } catch (\PDOException $exception) {
            $this->evidence->discard($storedEvidence);
            if (in_array($exception->getCode(), ['23000', '23505'], true)) {
                return ['success' => false, 'errors' => ['form' => 'This payment submission or D17 reference has already been recorded. Refresh the payment history.']];
            }

            throw $exception;
        } catch (\Throwable $exception) {
            $this->evidence->discard($storedEvidence);

            throw $exception;
        }

        return ['success' => true, 'errors' => [], 'paymentId' => $paymentId];
    }

    /** A payment with the facts needed by the verify/reject confirmation page, or null if it does not exist. */
    public function confirmation(int $paymentId, string $action): ?array
    {
        $payment = $this->payments->findForConfirmation($paymentId);
        if ($payment === null) {
            return null;
        }
        $payment['reviewable'] = (string) $payment['payment_method'] === 'D17'
            && (string) $payment['payment_status'] === 'PENDING_VERIFICATION';
        $payment['validAction'] = in_array($action, ['verify', 'reject'], true);
        $summary = $this->payments->findSummaryForOrder((int) $payment['order_id']);
        $payment['expected_amount'] = Money::remainingAmount((string) ($payment['payment_type'] === 'DEPOSIT' ? $payment['required_deposit_amount'] : $payment['total_amount']), (string) ($summary['verified_amount'] ?? '0.000'));

        return $payment;
    }

    /**
     * Marks a pending D17 payment as verified, unless that would exceed the order total.
     *
     * @return array{success: bool, errors: array<string, string>, orderId?: int, orderNumber?: string}
     */
    public function verify(int $paymentId, int $administratorId, DateTimeImmutable $now): array
    {
        if ($administratorId < 1) {
            return ['success' => false, 'errors' => ['form' => 'An administrator is required.']];
        }
        try {
            return $this->transactions->run(function () use ($paymentId, $administratorId, $now): array {
                [$order, $payment] = $this->lockPendingD17Payment($paymentId, 'verified');

                $sums = $this->payments->lockSumsForOrder((int) $payment['order_id']);
                $projectedVerified = Money::storedMillis($sums['verified']) + Money::storedMillis((string) $payment['amount']);
                if ($projectedVerified > Money::storedMillis((string) $order['total_amount'])) {
                    throw new DomainException('Verifying this payment would exceed the order total. Reject it instead.');
                }

                $this->payments->markVerified($paymentId, $administratorId, $now->format('Y-m-d H:i:s'));

                return [
                    'success' => true,
                    'errors' => [],
                    'orderId' => (int) $payment['order_id'],
                    'orderNumber' => (string) $payment['order_number'],
                ];
            });
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['form' => $exception->getMessage()]];
        }
    }

    /**
     * Marks a pending D17 payment as rejected, with a required reason (up to 500 characters).
     *
     * @return array{success: bool, errors: array<string, string>, orderId?: int, orderNumber?: string}
     */
    public function reject(int $paymentId, int $administratorId, string $reason): array
    {
        if ($administratorId < 1) {
            return ['success' => false, 'errors' => ['form' => 'An administrator is required.']];
        }
        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'errors' => ['reason' => 'A rejection reason is required.']];
        }
        if (Text::length($reason) > 500) {
            return ['success' => false, 'errors' => ['reason' => 'The rejection reason must be 500 characters or fewer.']];
        }

        try {
            return $this->transactions->run(function () use ($paymentId, $administratorId, $reason): array {
                $this->lockPendingD17Payment($paymentId, 'rejected');

                $this->payments->markRejected($paymentId, $administratorId, $reason);

                return [
                    'success' => true,
                    'errors' => [],
                    'orderId' => (int) $payment['order_id'],
                    'orderNumber' => (string) $payment['order_number'],
                ];
            });
        } catch (DomainException $exception) {
            return ['success' => false, 'errors' => ['form' => $exception->getMessage()]];
        }
    }

    /**
     * The private file path of a payment's proof for download, or null if there is none.
     *
     * @return array{orderId: mixed, path: string}|null
     */
    public function resolveEvidence(int $paymentId): ?array
    {
        $evidence = $this->payments->findEvidence($paymentId);
        if ($evidence === null || $evidence['path'] === null) {
            return null;
        }
        $resolved = $this->evidence->resolve($evidence['path']);
        if ($resolved === null) {
            return null;
        }

        return ['orderId' => $evidence['order_id'], 'path' => $resolved];
    }

    /** The detected MIME type of a stored proof file, for the download response. */
    public function evidenceMimeType(string $absolutePath): string
    {
        return $this->evidence->mimeType($absolutePath);
    }

    /**
     * Locks the payment's order, then the payment itself, and checks that it
     * is a D17 payment still awaiting review.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [order, payment]
     */
    private function lockPendingD17Payment(int $paymentId, string $action): array
    {
        $candidate = $this->payments->findForConfirmation($paymentId);
        $order = $candidate === null ? null : $this->payments->lockOrder((int) $candidate['order_id']);
        if ($order === null) {
            throw new DomainException('The associated order could not be found.');
        }
        $payment = $this->payments->lockPayment($paymentId);
        if ($payment === null) {
            throw new DomainException('The payment could not be found.');
        }
        if ((string) $payment['payment_method'] !== 'D17' || (string) $payment['payment_status'] !== 'PENDING_VERIFICATION') {
            throw new DomainException('Only a pending D17 payment can be ' . $action . '.');
        }

        return [$order, $payment];
    }
}
