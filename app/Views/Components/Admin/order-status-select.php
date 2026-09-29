<?php

declare(strict_types=1);

/**
 * @var array $statusControlOrder
 * @var array $statusControlOptions
 * @var string $statusControlReturn
 * @var string $statusControlDestination
 * @var string $statusControlReason
 * @var callable $escape
 */


/** Native status control shared by the list and detail page. */
$statusControlId = 'order-status-' . (int) $statusControlOrder['id'];
$statusControlDisabled = count(array_filter($statusControlOptions, static fn(array $option): bool => !$option['disabled'])) <= 1;
?>
<form class="admin-order-status-select" action="<?php echo BASE_URL . 'admin/orders/status.php'; ?>" method="post" data-order-status-form>
  <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
  <input type="hidden" name="id" value="<?php echo $escape($statusControlOrder['id']); ?>">
  <input type="hidden" name="inline_status" value="1">
  <input type="hidden" name="expected_status" value="<?php echo $escape($statusControlOrder['order_status']); ?>">
  <input type="hidden" name="return" value="<?php echo $escape($statusControlReturn); ?>">
  <input type="hidden" name="return_to" value="<?php echo $escape($statusControlDestination); ?>">
  <label class="admin-order-status-select__field" for="<?php echo $escape($statusControlId); ?>">
    <select id="<?php echo $escape($statusControlId); ?>" name="new_status" aria-label="Order status" aria-describedby="<?php echo $escape($statusControlId); ?>-help" <?php echo $statusControlDisabled ? ' disabled' : ''; ?>>
      <?php foreach ($statusControlOptions as $statusControlOption): ?>
        <option value="<?php echo $escape($statusControlOption['status']); ?>"
          <?php echo $statusControlOption['status'] === $statusControlOrder['order_status'] ? ' selected' : ''; ?>
          <?php echo $statusControlOption['disabled'] ? ' disabled' : ''; ?>>
          <?php echo $escape(ucwords(strtolower(str_replace('_', ' ', $statusControlOption['status'])))); ?><?php echo $statusControlOption['disabled'] ? (in_array($statusControlOption['status'], ['IN_DELIVERY', 'DELIVERED'], true) ? ' (delivery only)' : ' (pickup only)') : ''; ?>
        </option>
      <?php endforeach; ?>
    </select>
    <small id="<?php echo $escape($statusControlId); ?>-help"><?php echo $escape($statusControlDisabled ? $statusControlReason : ''); ?></small>
  </label>
  <button class="admin-button admin-button--secondary admin-order-status-select__save" type="submit" aria-label="Save order status" title="Save order status" data-status-save<?php echo $statusControlDisabled ? ' disabled' : ''; ?>>
    <i class="admin-order-status-select__save-icon" data-lucide="save-check" aria-hidden="true"></i>
  </button>
</form>
