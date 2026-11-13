<?php
declare(strict_types=1);
$taskLabels = ['CONFIRMED' => 'Confirm order','PREPARING' => 'Start preparation','PREPARED' => 'Mark prepared','WAITING_FOR_PICKUP' => 'Ready for pickup','PICKED_UP' => 'Confirm pickup','IN_DELIVERY' => 'Dispatch order','DELIVERED' => 'Confirm delivery'];
$next = $orderTransition['nextStatus'] ?? null;
$blocked = false;
$taskLink = null;
$taskTitle = $next === null ? 'Order completed' : ($taskLabels[$next] ?? 'Review order');
$taskDescription = (string)($orderTransition['consequence'] ?? 'The order history is available below.');
if ($order['order_status'] === 'CANCELLED') {
    $taskTitle = 'Order cancelled';
    $taskDescription = 'This order is closed. Recorded payments are retained for separate refund review.';
} elseif ($next === 'CONFIRMED' && !$orderTransition['allowed']) {
    $blocked = true;
    $taskTitle = 'Review the deposit';
    $taskDescription = 'Verified deposit still needed: ' . $order['deposit_due'] . ' TND. Pending transfers count only after review.';
    $taskLink = ['#order-payments','Review payments'];
} elseif (in_array($next, ['PREPARING','PREPARED'], true) && !empty($orderReadiness['missing'])) {
    $blocked = true;
    $taskTitle = 'Complete the production recipe';
    $taskDescription = 'Recipe needed for ' . implode(', ', $orderReadiness['missing']) . '.';
    $taskLink = ['#order-production','Complete recipe'];
} elseif (in_array($next, ['PREPARING','PREPARED'], true) && !empty($orderReadiness['shortages'])) {
    $blocked = true;
    $taskTitle = 'Review supplies before production';
    $taskDescription = 'Stock needs attention: ' . implode(', ', array_column($orderReadiness['shortages'], 'name')) . '.';
    $taskLink = ['#order-production','Review blockers'];
} elseif ($next === null && (float)$order['remaining_balance'] > 0 && $order['order_status'] !== 'CANCELLED') {
    $taskTitle = 'Record the remaining payment';
    $taskDescription = 'Fulfilment is complete. The remaining balance is ' . $order['remaining_balance'] . ' TND.';
    $taskLink = ['#order-payments','Record payment'];
}
?>
<section class="admin-task-panel<?php echo $blocked ? ' admin-task-panel--blocked' : ''; ?>" aria-label="Next action"><div><span class="admin-eyebrow"><?php echo $blocked ? 'NEEDS ATTENTION' : 'YOUR NEXT STEP'; ?></span><h2><?php echo $escape($taskTitle); ?></h2><p><?php echo $escape($taskDescription); ?></p></div>
<?php if ($taskLink !== null): ?><a class="admin-button" href="<?php echo $escape($taskLink[0]); ?>"><?php echo $escape($taskLink[1]); ?></a>
<?php elseif ($next !== null): ?><form action="<?php echo BASE_URL; ?>admin/orders/status.php" method="post"><input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>"><input type="hidden" name="id" value="<?php echo $escape($order['id']); ?>"><input type="hidden" name="inline_status" value="1"><input type="hidden" name="expected_status" value="<?php echo $escape($order['order_status']); ?>"><input type="hidden" name="new_status" value="<?php echo $escape($next); ?>"><input type="hidden" name="return" value="<?php echo $escape($orderDetail['backQuery']); ?>"><button class="admin-button" type="submit"><?php echo $escape($taskLabels[$next]); ?></button></form><?php endif; ?></section>
