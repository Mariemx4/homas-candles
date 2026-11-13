<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\FormInput;
use App\Database\TransactionManager;
use App\Repositories\OrderRepository;
use App\Security\CsrfTokenManager;
use App\Services\AccessoryStockService;
use App\Services\OrderEditService;
use DomainException;
use Throwable;

final class OrderTrashController
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly TransactionManager $transactions,
        private readonly CsrfTokenManager $csrf,
        private readonly AccessoryStockService $accessories
    ) {
    }

    public function process(array $post, int $administratorId, bool $restore = false): array
    {
        $token = FormInput::csrfToken($post);
        $valid = $this->csrf->validate($token);
        $this->csrf->rotate();
        $id = is_string($post['id'] ?? null) ? filter_var($post['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if (!$valid || $id === false || $administratorId < 1) {
            return ['success' => false, 'message' => 'This form expired or the order is invalid. Reload and try again.'];
        }
        try {
            return $this->transactions->run(function () use ($post, $id, $administratorId, $restore): array {
                $order = $this->orders->lockIncludingTrash($id);
                if ($order === null || ($restore ? $order['deleted_at'] === null : $order['deleted_at'] !== null)) {
                    return ['success' => false, 'message' => 'This order is no longer available for that action.'];
                }
                if (!$restore && (!is_string($post['version'] ?? null) || !hash_equals(OrderEditService::version($order), $post['version']))) {
                    return ['success' => false, 'message' => 'The order changed. Review the latest details before deleting it.'];
                }
                $status = (string) $order['order_status'];
                $stockNote = '';
                if ($restore) {
                    // Take the accessories again (or confirm they are still
                    // available); a DomainException refuses the restore.
                    $this->accessories->reinstateOrder($id, $status, $administratorId);
                }
                // Hiding an order never reverses its recorded stock consumption.
                $this->orders->setTrashed($id, !$restore, $administratorId);
                return ['success' => true, 'message' => $order['order_number'] . ($restore ? ' restored.' : ' moved to Trash.') . $stockNote];
            });
        } catch (DomainException $exception) {
            return ['success' => false, 'message' => $exception->getMessage() . ' The order was not changed.'];
        } catch (Throwable) {
            error_log('Order Trash action failed.');
            return ['success' => false, 'message' => 'The order could not be updated. Please try again.'];
        }
    }
}
