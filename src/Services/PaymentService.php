<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Business service for Payment Service; it contains application rules and coordinates related persistence work.
 *
 * DATA FLOW: Controllers call this layer. It validates and normalizes domain input, calls repositories or storage collaborators, and returns structured results without rendering HTML.
 */

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

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly TransactionManager $transactions,
        private readonly PaymentEvidenceStorage $evidence
    ) {
    }

    /**
     * FUNCTION PURPOSE: Prepare a filtered and paginated collection from the supplied query options.
     *
     * @return array<string, mixed>
     */
    public function list(array $query): array
    {
        $normalized = $this->normalizeListQuery($query);
        $filters = $normalized['filters'];
        $total = $this->payments->countForList($filters);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($normalized['page'], $totalPages);
        $rows = $total === 0 ? [] : $this->payments->findForList(
            $filters,
            ($page - 1) * self::PER_PAGE,
            self::PER_PAGE
        );
        $state = $this->queryState($normalized, $page);

        return [
            'payments' => $rows,
            'filters' => $filters,
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'total' => $total,
            'totalPages' => $totalPages,
            'queryState' => $state,
            // URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
            'returnQuery' => http_build_query($state),
            'filtersAdjusted' => $normalized['adjusted'],
        ];
    }

    /**
     * FUNCTION PURPOSE: Build the order summary data needed by its caller.
     *
     * @return array<string, mixed>|null
     */
    public function orderSummary(int $orderId): ?array
    {
        $summary = $this->payments->findSummaryForOrder($orderId);
        if ($summary === null) {
            return null;
        }
        $summary['remaining_balance'] = $this->subtractMoney(
            (string) $summary['total_amount'],
            (string) $summary['verified_amount']
        );

        return [
            'order' => $summary,
            'payments' => $this->payments->findPaymentsForOrder($orderId),
        ];
    }

    /**
     * FUNCTION PURPOSE: Validate and record the supplied value as a new event or movement.
     *
     * @param array<string, mixed> $input
     * @param array{name?: mixed, type?: mixed, tmp_name?: mixed, error?: mixed, size?: mixed}|null $evidenceFile
     * @return array{success: bool, errors: array<string, string>, paymentId?: int}
     */
    public function record(array $input, ?array $evidenceFile, int $administratorId, DateTimeImmutable $now): array
    {
        [$values, $errors] = $this->validateRecord($input, $now);
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
            // TRANSACTION IDIOM: Run related writes as one unit; an exception rolls back every write in this closure.
            $paymentId = $this->transactions->run(function () use ($values, $storedEvidence, $administratorId, $now): int {
                $order = $this->payments->lockOrder($values['order_id']);
                if ($order === null) {
                    throw new DomainException('The selected order could not be found.');
                }

                $sums = $this->payments->lockSumsForOrder($values['order_id']);
                $remainingMillis = $this->moneyToMillis((string) $order['total_amount'])
                    - $this->moneyToMillis($sums['verified'])
                    - $this->moneyToMillis($sums['pending']);
                if ($values['amount_millis'] > max(0, $remainingMillis)) {
                    throw new DomainException('This amount would exceed the order total once pending payments are verified.');
                }

                $isCash = $values['payment_method'] === 'CASH_ON_DELIVERY';

                return $this->payments->insert([
                    'order_id' => $values['order_id'],
                    'payment_method' => $values['payment_method'],
                    'payment_type' => $values['payment_type'],
                    'payment_status' => $isCash ? 'VERIFIED' : 'PENDING_VERIFICATION',
                    'amount' => $this->millisToMoney($values['amount_millis']),
                    'd17_reference' => $values['payment_method'] === 'D17' ? $values['d17_reference'] : null,
                    'payment_proof_path' => $values['payment_method'] === 'D17' ? $storedEvidence : null,
                    'customer_declared_paid_at' => $values['payment_method'] === 'D17'
                        ? $values['customer_declared_paid_at']
                        : null,
                    'verified_at' => $isCash ? $now->format('Y-m-d H:i:s') : null,
                    'verified_by_administrator_id' => $isCash ? $administratorId : null,
                ]);
            });
        } catch (DomainException $exception) {
            $this->evidence->discard($storedEvidence);

            return ['success' => false, 'errors' => ['form' => $exception->getMessage()]];
        } catch (\PDOException $exception) {
            $this->evidence->discard($storedEvidence);
            if ($exception->getCode() === '23000') {
                return ['success' => false, 'errors' => ['d17_reference' => 'This D17 reference has already been recorded.']];
            }

            throw $exception;
        } catch (\Throwable $exception) {
            $this->evidence->discard($storedEvidence);

            throw $exception;
        }

        return ['success' => true, 'errors' => [], 'paymentId' => $paymentId];
    }

    /**
     * FUNCTION PURPOSE: Build confirmation data for the supplied value before a state change.
     *
     * @return array<string, mixed>|null
     */
    public function confirmation(int $paymentId, string $action): ?array
    {
        $payment = $this->payments->findForConfirmation($paymentId);
        if ($payment === null) {
            return null;
        }
        $payment['reviewable'] = (string) $payment['payment_method'] === 'D17'
            && (string) $payment['payment_status'] === 'PENDING_VERIFICATION';
        $payment['validAction'] = in_array($action, ['verify', 'reject'], true);

        return $payment;
    }

    /**
     * FUNCTION PURPOSE: Verify the supplied value and persist the approved result.
     *
     * @return array{success: bool, errors: array<string, string>, orderId?: int, orderNumber?: string}
     */
    public function verify(int $paymentId, int $administratorId, DateTimeImmutable $now): array
    {
        try {
            // TRANSACTION IDIOM: Run related writes as one unit; an exception rolls back every write in this closure.
            return $this->transactions->run(function () use ($paymentId, $administratorId, $now): array {
                $payment = $this->payments->lockPayment($paymentId);
                if ($payment === null) {
                    throw new DomainException('The payment could not be found.');
                }
                if ((string) $payment['payment_method'] !== 'D17' || (string) $payment['payment_status'] !== 'PENDING_VERIFICATION') {
                    throw new DomainException('Only a pending D17 payment can be verified.');
                }

                $order = $this->payments->lockOrder((int) $payment['order_id']);
                if ($order === null) {
                    throw new DomainException('The associated order could not be found.');
                }
                $sums = $this->payments->lockSumsForOrder((int) $payment['order_id']);
                $projectedVerified = $this->moneyToMillis($sums['verified']) + $this->moneyToMillis((string) $payment['amount']);
                if ($projectedVerified > $this->moneyToMillis((string) $order['total_amount'])) {
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
     * FUNCTION PURPOSE: Reject the supplied value while preserving the reason and audit state.
     *
     * @return array{success: bool, errors: array<string, string>, orderId?: int, orderNumber?: string}
     */
    public function reject(int $paymentId, int $administratorId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['success' => false, 'errors' => ['reason' => 'A rejection reason is required.']];
        }
        if ($this->length($reason) > 500) {
            return ['success' => false, 'errors' => ['reason' => 'The rejection reason must be 500 characters or fewer.']];
        }

        try {
            // TRANSACTION IDIOM: Run related writes as one unit; an exception rolls back every write in this closure.
            return $this->transactions->run(function () use ($paymentId, $administratorId, $reason): array {
                $payment = $this->payments->lockPayment($paymentId);
                if ($payment === null) {
                    throw new DomainException('The payment could not be found.');
                }
                if ((string) $payment['payment_method'] !== 'D17' || (string) $payment['payment_status'] !== 'PENDING_VERIFICATION') {
                    throw new DomainException('Only a pending D17 payment can be rejected.');
                }

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
     * FUNCTION PURPOSE: Resolve evidence into the safe concrete value needed by the caller.
     *
     * @return array{orderId: int, path: string}|null
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

    public function evidenceMimeType(string $absolutePath): string
    {
        return $this->evidence->mimeType($absolutePath);
    }

    /**
     * FUNCTION PURPOSE: Validate and convert list query into a consistent internal shape.
     *
     * @return array<string, mixed>
     */
    private function normalizeListQuery(array $query): array
    {
        $adjusted = false;
        $search = trim($this->queryString($query, 'q', $adjusted));
        if ($this->length($search) > 100) {
            $search = substr($search, 0, 100);
            $adjusted = true;
        }
        $status = $this->allowQuery($query, 'status', self::STATUSES, $adjusted);
        $method = $this->allowQuery($query, 'method', self::METHODS, $adjusted);
        $dateFrom = $this->queryDate($query, 'date_from', $adjusted);
        $dateTo = $this->queryDate($query, 'date_to', $adjusted);
        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            $dateFrom = $dateTo = null;
            $adjusted = true;
        }
        $pageText = $this->queryString($query, 'page', $adjusted);
        // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
        $page = $pageText === '' ? 1 : filter_var($pageText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        if ($page === false) {
            $page = 1;
            $adjusted = true;
        }

        return [
            'filters' => [
                'search' => $search === '' ? null : $search,
                'status' => $status,
                'method' => $method,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'page' => (int) $page,
            'adjusted' => $adjusted,
        ];
    }

    /**
     * FUNCTION PURPOSE: Read and normalize the state query value.
     *
     * @return array<string, mixed>
     */
    private function queryState(array $normalized, int $page): array
    {
        $filters = $normalized['filters'];

        // COLLECTION IDIOM: Keep only array items for which the callback returns true.
        return array_filter([
            'q' => $filters['search'],
            'status' => $filters['status'],
            'method' => $filters['method'],
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'page' => $page,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * FUNCTION PURPOSE: Check record against the application rules and return usable values or errors.
     *
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function validateRecord(array $input, DateTimeImmutable $now): array
    {
        $errors = [];
        $string = static fn (string $key): string => isset($input[$key]) && is_string($input[$key]) ? trim($input[$key]) : '';

        // VALIDATION IDIOM: Ask PHP to validate/convert this untrusted scalar instead of relying on a loose type cast.
        $orderId = filter_var($string('order_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($orderId === false) {
            $errors['order_id'] = 'The order identifier is invalid.';
            $orderId = 0;
        }

        $method = strtoupper($string('payment_method'));
        if (!in_array($method, self::METHODS, true)) {
            $errors['payment_method'] = 'Select a supported payment method.';
        }

        $type = strtoupper($string('payment_type'));
        if (!in_array($type, self::TYPES, true)) {
            $errors['payment_type'] = 'Select a valid payment type.';
        }

        $amountMillis = $this->parseMoney($string('amount'));
        if ($amountMillis === null || $amountMillis <= 0) {
            $errors['amount'] = 'Enter an amount greater than zero, with up to three decimals.';
            $amountMillis = 0;
        }

        $reference = $string('d17_reference');
        if ($this->length($reference) > 100) {
            $errors['d17_reference'] = 'The D17 reference must be 100 characters or fewer.';
        }
        if ($method !== 'D17' && $reference !== '') {
            $errors['d17_reference'] = 'A D17 reference can only be recorded for D17 payments.';
        }

        $declaredAtRaw = $string('customer_declared_paid_at');
        $declaredAt = null;
        if ($declaredAtRaw !== '') {
            if ($method !== 'D17') {
                $errors['customer_declared_paid_at'] = 'A customer-declared date only applies to D17 payments.';
            } else {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $declaredAtRaw);
                if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d\TH:i') !== $declaredAtRaw || $date > $now) {
                    $errors['customer_declared_paid_at'] = 'Enter a valid date and time that is not in the future.';
                } else {
                    $declaredAt = $date->format('Y-m-d H:i:s');
                }
            }
        }

        return [[
            'order_id' => (int) $orderId,
            'payment_method' => $method,
            'payment_type' => $type,
            'amount_millis' => $amountMillis,
            'd17_reference' => $reference === '' ? null : $reference,
            'customer_declared_paid_at' => $declaredAt,
        ], $errors];
    }

    /**
     * FUNCTION PURPOSE: Parse money text without accepting unsupported formats.
     */
    private function parseMoney(string $value): ?int
    {
        // VALIDATION IDIOM: Test the complete string against a regular-expression format before accepting it.
        if (preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.([0-9]{1,3}))?$/', $value, $matches) !== 1) {
            return null;
        }

        return ((int) explode('.', $value)[0] * 1000) + (int) str_pad($matches[1] ?? '', 3, '0');
    }

    /**
     * FUNCTION PURPOSE: Subtract money using exact integer money arithmetic.
     */
    private function subtractMoney(string $total, string $paid): string
    {
        $remaining = max(0, $this->moneyToMillis($total) - $this->moneyToMillis($paid));

        return $this->millisToMoney($remaining);
    }

    /**
     * FUNCTION PURPOSE: Convert to millis using integer thousandths to avoid floating-point errors.
     */
    private function moneyToMillis(string $amount): int
    {
        // VALIDATION IDIOM: Test the complete string against a regular-expression format before accepting it.
        if (preg_match('/^(\d+)(?:\.(\d{1,3}))?$/', $amount, $matches) !== 1) {
            return 0;
        }

        return ((int) $matches[1] * 1000) + (int) str_pad($matches[2] ?? '', 3, '0');
    }

    /**
     * FUNCTION PURPOSE: Convert to money between integer thousandths and a money string.
     */
    private function millisToMoney(int $millis): string
    {
        return intdiv($millis, 1000) . '.' . str_pad((string) ($millis % 1000), 3, '0', STR_PAD_LEFT);
    }

    /**
     * FUNCTION PURPOSE: Read and normalize the string query value.
     */
    private function queryString(array $query, string $key, bool &$adjusted): string
    {
        if (!array_key_exists($key, $query)) {
            return '';
        }
        if (!is_string($query[$key])) {
            $adjusted = true;

            return '';
        }

        return $query[$key];
    }

    /**
     * FUNCTION PURPOSE: Accept query only when it belongs to the allowed set.
     *
     * @param list<string> $allowed
     */
    private function allowQuery(array $query, string $key, array $allowed, bool &$adjusted): ?string
    {
        $value = $this->queryString($query, $key, $adjusted);
        if ($value === '') {
            return null;
        }
        if (!in_array($value, $allowed, true)) {
            $adjusted = true;

            return null;
        }

        return $value;
    }

    /**
     * FUNCTION PURPOSE: Read and normalize the date query value.
     */
    private function queryDate(array $query, string $key, bool &$adjusted): ?string
    {
        $value = $this->queryString($query, $key, $adjusted);
        if ($value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            $adjusted = true;

            return null;
        }

        return $value;
    }

    /**
     * FUNCTION PURPOSE: Count characters in the supplied value safely for UTF-8 validation.
     */
    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
