<?php

declare(strict_types=1);

use App\View\AdminIcons;

/**
 * @var array $editFlash
 * @var object $order
 * @var string $detailUrl
 * @var string $editUrl
 * @var callable $escape
 * @var callable $editValues
 */

$fieldError = static fn (string $field): string => (string) ($editFlash['errors'][$field] ?? '');
$input = static function (string $field, string $label, string $type = 'text', int $maximum = 255, bool $required = false, string $pattern = '') use ($editValues, $fieldError, $escape): void {
    $error = $fieldError($field);
    ?>
    <div class="admin-field">
      <label for="edit-<?php echo $escape($field); ?>"><?php echo $escape($label); ?></label>
      <input id="edit-<?php echo $escape($field); ?>" name="<?php echo $escape($field); ?>" type="<?php echo $escape($type); ?>" maxlength="<?php echo $maximum; ?>" value="<?php echo $escape($editValues[$field] ?? ''); ?>"<?php echo $required ? ' required' : ''; ?><?php if ($pattern !== ''): ?> pattern="<?php echo $escape($pattern); ?>"<?php endif; ?><?php if ($error !== ''): ?> aria-invalid="true" aria-describedby="error-<?php echo $escape($field); ?>"<?php endif; ?>>
      <?php if ($error !== ''): ?><small class="admin-field-error" id="error-<?php echo $escape($field); ?>"><?php echo $escape($error); ?></small><?php endif; ?>
    </div>
    <?php
};
?>
<header class="admin-page-header admin-page-header--detail">
  <div>
    <p class="admin-eyebrow"><?php echo $escape($order['order_number']); ?></p>
    <h1>Edit order</h1>
    <p class="admin-page-header__summary">Update customer details, fulfilment, the required date, and notes.</p>
  </div>
  <a class="admin-button admin-button--secondary" href="<?php echo $escape($detailUrl); ?>"><?php echo AdminIcons::render('back'); ?>Cancel</a>
</header>
<?php if ($editFlash['errors'] !== []): ?>
  <?php
    $alertTone = 'error';
    $alertTitle = 'Order not updated';
    $alertMessage = implode(' ', array_unique(array_values($editFlash['errors'])));
    require VIEW_PATH . '/Components/Admin/alert.php';
    ?>
  <p><a class="admin-back-link" href="<?php echo $escape($editUrl); ?>">Reload latest order</a></p>
<?php endif; ?>
<form class="admin-panel admin-conversion-form" action="<?php echo $escape($editUrl); ?>" method="post" data-delivery-fee-default="<?php echo $escape($deliveryFeeDefault ?? \App\Services\Money::formatMillis((require APP_PATH . '/config/checkout.php')['delivery_fee_millis'])); ?>">
  <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
  <input type="hidden" name="version" value="<?php echo $escape($editValues['version'] ?? ''); ?>">
  <fieldset>
    <legend>Customer</legend>
    <?php
        $customerModeAllowNew = false;
$customerContactValues = $editValues;
$customerContactErrors = $editFlash['errors'];
require VIEW_PATH . '/Components/Admin/order-customer-picker.php';
?>
  </fieldset>
  <fieldset>
    <legend>Fulfilment</legend>
    <div class="admin-form-grid">
      <div class="admin-field">
        <label for="edit-delivery_method">Method</label>
        <?php if ($canChangeFulfilment): ?>
          <select id="edit-delivery_method" name="delivery_method" required>
            <option value="PICKUP"<?php echo ($editValues['delivery_method'] ?? '') === 'PICKUP' ? ' selected' : ''; ?>>Pickup</option>
            <option value="DELIVERY"<?php echo ($editValues['delivery_method'] ?? '') === 'DELIVERY' ? ' selected' : ''; ?>>Delivery</option>
          </select>
        <?php else: ?>
          <input id="edit-delivery_method" name="delivery_method" value="<?php echo $escape($order['delivery_method']); ?>" readonly>
        <?php endif; ?>
      </div>
      <?php $input('required_date', 'Required date', 'date', 10, true); ?>
      <?php if ($canChangeFulfilment): ?>
        <?php $input('delivery_fee', 'Delivery fee (TND)', 'text', 13, true, '[0-9]+(?:\.[0-9]{1,3})?'); ?>
      <?php else: ?>
        <div class="admin-field"><label for="edit-delivery_fee">Delivery fee (TND)</label><input id="edit-delivery_fee" name="delivery_fee" value="<?php echo $escape($order['delivery_fee']); ?>" readonly></div>
      <?php endif; ?>
      <div class="admin-field admin-field--wide"><small>Pickup: 0.000 TND. Delivery: at least 9.000 TND. Switching methods updates the total and any delivery or pickup status. Existing payments stay recorded; an overpayment will appear as a credit.</small></div>
      <?php $input('street_address', 'Street address'); ?>
      <?php $input('delegation', 'Delegation', 'text', 100); ?>
      <?php $input('governorate', 'Governorate', 'text', 100); ?>
      <?php $input('postal_code', 'Postal code', 'text', 4, false, '[0-9]{4}'); ?>
    </div>
  </fieldset>
  <fieldset>
    <legend>Notes</legend>
    <div class="admin-form-grid">
      <?php foreach (['customer_notes' => 'Customer notes', 'administrator_notes' => 'Administrator notes'] as $field => $label): ?>
        <div class="admin-field admin-field--wide">
          <label for="edit-<?php echo $escape($field); ?>"><?php echo $escape($label); ?></label>
          <textarea id="edit-<?php echo $escape($field); ?>" name="<?php echo $escape($field); ?>" rows="4" maxlength="10000"><?php echo $escape($editValues[$field] ?? ''); ?></textarea>
        </div>
      <?php endforeach; ?>
    </div>
  </fieldset>
  <div class="admin-conversion-form__actions">
    <a class="admin-button admin-button--secondary" href="<?php echo $escape($detailUrl); ?>"><?php echo AdminIcons::render('back'); ?>Cancel</a>
    <button class="admin-button" type="submit"><?php echo AdminIcons::render('save'); ?>Save changes</button>
  </div>
</form>
<script src="<?php echo BASE_URL . 'assets/js/admin/order-details.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin/order-details.js'); ?>" defer></script>
