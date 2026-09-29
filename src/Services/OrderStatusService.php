<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Business service for Order Status Service; it contains application rules and coordinates related persistence work.
 *
 * DATA FLOW: Controllers call this layer. It validates and normalizes domain input, calls repositories or storage collaborators, and returns structured results without rendering HTML.
 */

namespace App\Services;

use App\Database\TransactionManager;
use App\Repositories\MaterialStockRepository;
use App\Repositories\OrderStatusRepository;
use DomainException;

final class OrderStatusService
{
    private const LINEAR_NEXT = [
        'REQUESTED' => 'CONFIRMED',
        'CONFIRMED' => 'PREPARING',
        'PREPARING' => 'PREPARED',
        'WAITING_FOR_PICKUP' => 'PICKED_UP',
        'IN_DELIVERY' => 'DELIVERED',
    ];

    private const CONSEQUENCES = [
        'CONFIRMED' => 'Accepts the order for fulfilment. Any required deposit must already be verified.',
        'PREPARING' => 'Marks production as started. Inventory is not deducted at this step.',
        'PREPARED' => 'Locks the agreed price and deducts tracked consumable recipe materials and accessory stock exactly once.',
        'WAITING_FOR_PICKUP' => 'Marks a prepared pickup order as ready for the customer.',
        'PICKED_UP' => 'Completes a pickup order. The status can be corrected from the order status list.',
        'IN_DELIVERY' => 'Marks a prepared delivery order as dispatched.',
        'DELIVERED' => 'Completes a delivery order. The status can be corrected from the order status list.',
    ];

    /**
     * FUNCTION PURPOSE: Receive and store the dependencies this object needs; the bootstrap file supplies them.
     */
    public function __construct(
        private readonly OrderStatusRepository $statuses,
        private readonly MaterialStockRepository $stock,
        private readonly TransactionManager $transactions,
        private readonly OrderProductionService $production,
        private readonly AccessoryStockService $accessories
    ) {
    }

    /**
     * FUNCTION PURPOSE: Convert the supplied record into a compact summary for the caller.
     *
     * @param array<string, mixed> $order @return array<string, mixed>
     */
    public function summary(array $order): array
    {
        $current = (string) ($order['order_status'] ?? '');
        $method = (string) ($order['delivery_method'] ?? '');
        $next = $this->nextStatus($current, $method);
        if ($next === null) {
            return [
                'currentStatus' => $current,
                'nextStatus' => null,
                'allowed' => false,
                'reason' => in_array($current, ['PICKED_UP', 'DELIVERED'], true)
                    ? 'This order is complete and has no further status transition.'
                    : 'No legal next status exists for this order state and fulfilment method.',
                'consequence' => null,
            ];
        }

        $reason = null;
        if ($next === 'CONFIRMED'
            && $this->moneyToMillis((string) ($order['amount_paid'] ?? '0.000'))
                < $this->moneyToMillis((string) ($order['required_deposit_amount'] ?? '0.000'))
        ) {
            $reason = 'The required deposit has not yet been fully verified.';
        }

        return [
            'currentStatus' => $current,
            'nextStatus' => $next,
            'allowed' => $reason === null,
            'reason' => $reason,
            'consequence' => self::CONSEQUENCES[$next],
        ];
    }

    /** Always show the complete list; only the other fulfilment method is unavailable. */
    public function selectionOptions(array $order): array
    {
        $path = $this->statusPath((string) $order['delivery_method']);
        $options = [];
        foreach (OrderService::STATUSES as $status) {
            $options[] = ['status' => $status, 'disabled' => !in_array($status, $path, true)];
        }
        return $options;
    }

    /** Apply progress or correct an earlier status without rewriting material usage or prices. */
    public function selectStatus(int $orderId, int $administratorId, string $proposedStatus, string $expectedStatus): array
    {
        if ($orderId < 1 || $administratorId < 1 || !in_array($proposedStatus, OrderService::STATUSES, true)) {
            return $this->failure('new_status', 'Select a valid order status.');
        }
        try {
            return $this->transactions->run(function () use ($orderId, $administratorId, $proposedStatus, $expectedStatus): array {
                $order = $this->statuses->lockOrder($orderId);
                if ($order === null) {
                    throw new DomainException('The requested order could not be found.');
                }
                if ($order['order_status'] !== $expectedStatus) {
                    throw new DomainException('The order status changed in another session. Review the current status and choose again.');
                }
                $statusPath = $this->statusPath((string) $order['delivery_method']);
                $currentPosition = array_search($order['order_status'], $statusPath, true);
                $proposedPosition = array_search($proposedStatus, $statusPath, true);
                if ($proposedPosition === false || $currentPosition === false) {
                    throw new DomainException('Choose a status matching the order’s delivery or pickup method.');
                }
                if ($proposedPosition < $currentPosition) {
                    $this->statuses->updateStatus($orderId, $expectedStatus, $proposedStatus);
                    $this->statuses->addHistory($orderId, $expectedStatus, $proposedStatus, $administratorId,
                        'Administrator corrected the order status.');
                    return ['success' => true, 'errors' => [], 'orderNumber' => $order['order_number'], 'newStatus' => $proposedStatus];
                }
                $path = [];
                $status = (string) $order['order_status'];
                while ($status !== $proposedStatus) {
                    $status = $this->nextStatus($status, (string) $order['delivery_method']);
                    if ($status === null) {
                        throw new DomainException('That status is not available for this order.');
                    }
                    $path[] = $status;
                }
                foreach ($path as $status) {
                    $result = $this->transition($orderId, $administratorId, $status, null);
                    if (!$result['success']) {
                        // Throw so earlier steps and material deductions also roll back.
                        throw new DomainException(implode(' ', $result['errors']));
                    }
                }
                return ['success' => true, 'errors' => [], 'orderNumber' => $order['order_number'], 'newStatus' => $proposedStatus];
            });
        } catch (DomainException $exception) {
            return $this->failure('new_status', $exception->getMessage());
        }
    }

    /**
     * FUNCTION PURPOSE: Move the supplied value to an allowed next state and record the change.
     *
     * @return array{success: bool, errors: array<string, string>, orderNumber?: string, newStatus?: string}
     */
    public function transition(int $orderId, int $administratorId, string $proposedStatus, ?string $note): array
    {
        $proposedStatus = strtoupper(trim($proposedStatus));
        $note = $note === null ? null : trim($note);
        if ($administratorId < 1 || $orderId < 1) {
            return $this->failure('transition', 'The transition request is invalid.');
        }
        if (!in_array($proposedStatus, OrderService::STATUSES, true)) {
            return $this->failure('new_status', 'Select a valid order status.');
        }
        if ($note !== null && strlen($note) > 500) {
            return $this->failure('note', 'The administrator note must be 500 characters or fewer.');
        }
        $note = $note === '' ? null : $note;

        try {
            // TRANSACTION IDIOM: Run related writes as one unit; an exception rolls back every write in this closure.
            return $this->transactions->run(function () use (
                $orderId,
                $administratorId,
                $proposedStatus,
                $note
            ): array {
                $order = $this->statuses->lockOrder($orderId);
                if ($order === null) {
                    throw new DomainException('The requested order could not be found.');
                }

                $currentStatus = (string) $order['order_status'];
                $expectedStatus = $this->nextStatus($currentStatus, (string) $order['delivery_method']);
                if ($expectedStatus === null || $proposedStatus !== $expectedStatus) {
                    throw new DomainException('That status transition is not permitted from the order’s current state.');
                }

                if ($proposedStatus === 'CONFIRMED') {
                    $verifiedTotal = $this->statuses->verifiedPaymentTotalForUpdate($orderId);
                    if ($this->moneyToMillis($verifiedTotal)
                        < $this->moneyToMillis((string) $order['required_deposit_amount'])
                    ) {
                        throw new DomainException('The required deposit must be fully verified before confirmation.');
                    }
                }

                // WHAT: production cannot start or finish while an item lacks a
                //       catalogue recipe or an explicit custom recipe.
                // WHY:  the deduction below reads those recipes; an item without
                //       one would silently consume nothing. Throwing rolls back
                //       this step and, in selectStatus(), every earlier step.
                if (in_array($proposedStatus, OrderProductionService::PRODUCTION_STATUSES, true)) {
                    $this->production->assertReadyForProduction($orderId);
                }

                // Correcting a status does not undo physical material use or an agreed price.
                // A previously prepared order must never deduct materials a second time,
                // even if its recipe changed while its displayed status was corrected.
                // "Previously prepared" is read from the status history, not from
                // price_locked_at: manual orders lock their price at creation, so
                // that marker used to skip their deduction entirely.
                $firstPreparation = $proposedStatus === 'PREPARED' && !$this->statuses->hasReachedStatus($orderId, 'PREPARED');

                // WHAT: ready-made accessories (ordered directly or inside a set)
                //       leave the shelf at the same moment, with their own ledger.
                // WHY:  they are products, not recipe materials, so the material
                //       deduction below never saw them. A shortage throws and
                //       rolls back the whole status change.
                if ($firstPreparation) {
                    $this->accessories->deductForOrder($orderId, $administratorId);
                }

                if ($firstPreparation && !$this->statuses->hasStockDeduction($orderId)) {
                    foreach ($this->statuses->findMaterialRequirements($orderId) as $requirement) {
                        $quantity = (string) $requirement['required_quantity'];
                        if ($this->moneyToMillis($quantity) === 0) {
                            continue;
                        }
                        $this->stock->recordMovement(
                            (int) $requirement['material_id'],
                            'ORDER_DEDUCTION',
                            '-' . $quantity,
                            $orderId,
                            $administratorId,
                            'Materials deducted when order became prepared.'
                        );
                    }
                }

                $this->statuses->updateStatus($orderId, $currentStatus, $proposedStatus);
                $this->statuses->addHistory(
                    $orderId,
                    $currentStatus,
                    $proposedStatus,
                    $administratorId,
                    $note
                );

                return [
                    'success' => true,
                    'errors' => [],
                    'orderNumber' => (string) $order['order_number'],
                    'newStatus' => $proposedStatus,
                ];
            });
        } catch (DomainException $exception) {
            return $this->failure('transition', $exception->getMessage());
        }
    }

    /**
     * FUNCTION PURPOSE: Apply the operation represented by `nextStatus` (next status) and return its result.
     */
    private function nextStatus(string $currentStatus, string $deliveryMethod): ?string
    {
        if ($currentStatus === 'PREPARED') {
            // BRANCHING IDIOM: `match` selects exactly one result using strict comparisons and rejects/handles a default explicitly.
            return match ($deliveryMethod) {
                'PICKUP' => 'WAITING_FOR_PICKUP',
                'DELIVERY' => 'IN_DELIVERY',
                default => null,
            };
        }

        return self::LINEAR_NEXT[$currentStatus] ?? null;
    }

    private function statusPath(string $deliveryMethod): array
    {
        return array_merge(['REQUESTED', 'CONFIRMED', 'PREPARING', 'PREPARED'],
            $deliveryMethod === 'PICKUP' ? ['WAITING_FOR_PICKUP', 'PICKED_UP'] : ['IN_DELIVERY', 'DELIVERED']);
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
     * FUNCTION PURPOSE: Build a consistent failure result for the supplied value.
     *
     * @return array{success: false, errors: array<string, string>}
     */
    private function failure(string $field, string $message): array
    {
        return ['success' => false, 'errors' => [$field => $message]];
    }
}
