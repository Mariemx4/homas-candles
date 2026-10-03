<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Page view for Admin Order Create; it turns prepared application data into HTML.
 *
 * DATA FLOW: public/admin/orders/create.php prepares the flash values and the
 * orderable catalogue/materials, then the admin layout requires this view.
 *
 * @var array $manualOrderFlash
 * @var array $manualOrderValues
 * @var array{products: array<int, array<string, mixed>>, materials: array<int, array<string, mixed>>} $manualOrderOptions
 * @var object $csrf
 * @var callable $escape
 */


$isDelivery = ($manualOrderValues['delivery_method'] ?? '') === 'DELIVERY';
$isNewAccount = ($manualOrderValues['customer_mode'] ?? '') === 'new';
$copyAccountAddress = $isNewAccount && ($manualOrderValues['copy_account_address'] ?? '1') === '1';
$showOrderAddress = $isDelivery && !$copyAccountAddress;
$manualOrderPricing ??= ['success' => false];
$fieldError = static fn (string $key): string => isset($manualOrderFlash['errors'][$key]) ? (string) $manualOrderFlash['errors'][$key] : '';
$blankItem = ['product_id' => '', 'color_id' => '', 'scent_id' => '', 'product_name' => '', 'quantity' => '1', 'unit_price' => '', 'customization_notes' => '', 'recipe' => []];
$items = isset($manualOrderValues['items']) && is_array($manualOrderValues['items']) && $manualOrderValues['items'] !== []
    ? array_values($manualOrderValues['items'])
    : [$blankItem];
$catalogue = $manualOrderOptions['products'];
$materials = $manualOrderOptions['materials'];
$value = static fn (mixed $item, string $key, string $default = ''): string => is_array($item) && is_string($item[$key] ?? null) ? $item[$key] : $default;

$renderItem = static function (?int $index, mixed $item) use ($escape, $value, $catalogue, $fieldError, $manualOrderPricing): void {
    $name = static fn (string $field): string => $index === null ? '' : ' id="order-item-' . $index . '-' . $field . '" name="items[' . $index . '][' . $field . ']"';
    $productId = $value($item, 'product_id');
    $product = ctype_digit($productId) ? ($catalogue[(int) $productId] ?? null) : null;
    $price = $index === null ? null : ($manualOrderPricing['lines'][$index] ?? null);
    $override = $value($item, 'price_override') === '1';
    ?>
    <section class="admin-manual-item" data-order-item>
      <div class="admin-manual-item__heading"><h3>Item <span data-item-number><?php echo $escape($index === null ? '' : $index + 1); ?></span></h3><button class="admin-button admin-button--secondary" type="button" data-remove-item>Remove item</button></div>
      <div class="admin-form-grid">
        <div class="admin-field admin-field--wide"><label for="order-item-<?php echo $escape($index); ?>-product_id">Product</label><select data-item-field="product_id"<?php echo $name('product_id'); ?> data-product-select required>
          <option value="">Select a catalogue product</option>
          <?php foreach ($catalogue as $option): ?><option value="<?php echo $escape($option['id']); ?>"<?php echo $productId === (string) $option['id'] ? ' selected' : ''; ?>><?php echo $escape($option['name']); ?></option><?php endforeach; ?>
        </select></div>
        <?php foreach (['color_id' => ['Color', 'colors'], 'scent_id' => ['Scent', 'scents']] as $field => [$label, $options]): ?>
          <div class="admin-field" data-option-group="<?php echo $escape($options); ?>"<?php echo empty($product[$options]) ? ' hidden' : ''; ?>><label for="order-item-<?php echo $escape($index . '-' . $field); ?>"><?php echo $escape($label); ?></label><select data-item-field="<?php echo $escape($field); ?>"<?php echo $name($field); ?> data-option-select="<?php echo $escape($options); ?>"<?php echo empty($product[$options]) ? ' disabled' : ' required'; ?>>
            <option value="">Select <?php echo $escape(strtolower($label)); ?></option>
            <?php foreach ($product[$options] ?? [] as $id => $optionName): ?><option value="<?php echo $escape($id); ?>"<?php echo $value($item, $field) === (string) $id ? ' selected' : ''; ?>><?php echo $escape($optionName); ?></option><?php endforeach; ?>
          </select></div>
        <?php endforeach; ?>
        <div class="admin-field"><label for="order-item-<?php echo $escape($index); ?>-quantity">Quantity</label><input data-item-field="quantity"<?php echo $name('quantity'); ?> type="number" min="<?php echo $escape($product['minimum_quantity'] ?? 1); ?>" max="1000000" step="1" required value="<?php echo $escape($value($item, 'quantity', '1')); ?>"></div>
        <dl class="admin-item-price" aria-live="polite">
          <div><dt>Catalogue unit price</dt><dd data-line-base><?php echo $price === null ? 'Select a product' : $escape($price['base'] . ' TND'); ?></dd></div>
          <div data-line-tier-row<?php echo $price !== null && $price['base'] !== $price['quantity_price'] ? '' : ' hidden'; ?>><dt>Quantity price / unit</dt><dd data-line-tier><?php echo $escape(($price['quantity_price'] ?? '') . ' TND'); ?></dd></div>
          <div><dt>Line subtotal</dt><dd data-line-total><?php echo $price === null ? 'Review prices' : $escape($price['line'] . ' TND'); ?></dd></div>
        </dl>
        <div class="admin-field admin-field--wide"><label for="order-item-<?php echo $escape($index); ?>-customization_notes">Customization notes (optional)</label><textarea data-item-field="customization_notes"<?php echo $name('customization_notes'); ?> maxlength="10000" rows="3"><?php echo $escape($value($item, 'customization_notes')); ?></textarea></div>
      </div>
      <details class="admin-price-override"<?php echo $override ? ' open' : ''; ?>><summary>Exceptional price override</summary>
        <label><input type="checkbox" data-item-field="price_override"<?php echo $name('price_override'); ?> value="1" data-price-override<?php echo $override ? ' checked' : ''; ?>> Override this item's calculated unit price</label>
        <div class="admin-form-grid" data-override-fields<?php echo $override ? '' : ' hidden'; ?>>
          <div class="admin-field"><label for="order-item-<?php echo $escape($index); ?>-unit_price">Override unit price (TND)</label><input data-item-field="unit_price"<?php echo $name('unit_price'); ?> inputmode="decimal" value="<?php echo $escape($value($item, 'unit_price')); ?>"<?php echo $override ? ' required' : ' disabled'; ?>></div>
          <div class="admin-field"><label for="order-item-<?php echo $escape($index); ?>-price_override_reason">Override reason</label><input data-item-field="price_override_reason"<?php echo $name('price_override_reason'); ?> maxlength="500" value="<?php echo $escape($value($item, 'price_override_reason')); ?>"<?php echo $override ? ' required' : ' disabled'; ?>></div>
        </div>
      </details>
      <?php if ($index !== null && $fieldError('items_' . $index) !== ''): ?><p class="admin-field-error" role="alert"><?php echo $escape($fieldError('items_' . $index)); ?> <?php if ($product !== null): ?><a href="<?php echo BASE_URL . 'admin/catalogue/products.php?id=' . $escape($productId); ?>">Configure product in Catalogue</a><?php endif; ?></p><?php endif; ?>
    </section>
    <?php
};

// The browser only needs names and option lists to narrow the color/scent selects.
$catalogueJson = [];
foreach ($catalogue as $option) {
    $catalogueJson[(string) $option['id']] = [
        // COLLECTION IDIOM: array_map pairs each option ID with its name, keeping the server's order.
        'colors' => array_map(null, array_keys($option['colors']), array_values($option['colors'])),
        'scents' => array_map(null, array_keys($option['scents']), array_values($option['scents'])),
        'minimum_quantity' => $option['minimum_quantity'],
    ];
}
?>
<!-- This page only presents values. Validation, totals, and persistence live in the service layer. -->
<header class="admin-page-header admin-page-header--detail">
  <div>
    <h1>Create manual order</h1>
    <p class="admin-page-header__summary">Record a phone, social-media, or in-person order with frozen customer, item, option, cost, and recipe details.</p>
  </div>
  <a class="admin-button admin-button--secondary" href="<?php echo BASE_URL . 'admin/orders/index.php'; ?>">Cancel</a>
</header>

<?php if ($manualOrderFlash['errors'] !== []): ?>
  <?php
    $alertTone = 'error';
    $alertTitle = 'Order not created';
    $alertMessage = implode(' ', array_unique(array_values($manualOrderFlash['errors'])));
    require __DIR__ . '/../../Components/Admin/alert.php';
  ?>
<?php endif; ?>

<form class="admin-panel admin-conversion-form" action="<?php echo BASE_URL . 'admin/orders/create.php'; ?>" method="post" data-manual-order-form data-price-preview="<?php echo BASE_URL . 'admin/orders/price-preview.php'; ?>" data-delivery-fee-default="<?php echo $escape($deliveryFeeDefault); ?>">
  <!-- CSRF binds this state-changing submission to the administrator session. -->
  <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">

  <fieldset>
    <legend>Customer</legend>
    <?php
      $customerModeAllowNew = true;
      $customerContactValues = $manualOrderValues;
      $customerContactErrors = $manualOrderFlash['errors'];
      require __DIR__ . '/../../Components/Admin/order-customer-picker.php';
    ?>
  </fieldset>

  <fieldset>
    <legend>Items</legend>
    <?php if ($catalogue === []): ?><p>No active catalogue products are available.</p><?php endif; ?>
    <p class="admin-customer-mode-help">For a one-off design, <a href="<?php echo BASE_URL . 'admin/requests/create.php'; ?>">record a Custom Request</a>.</p>
    <div class="admin-manual-items" data-order-items>
      <?php foreach ($items as $index => $item): ?>
        <?php $renderItem($index, $item); ?>
      <?php endforeach; ?>
    </div>
    <button class="admin-button admin-button--secondary" type="button" data-add-item>Add another item</button>
  </fieldset>

  <fieldset>
    <legend>Fulfilment</legend>
    <div class="admin-form-grid">
      <div class="admin-field">
        <label for="delivery-method">Method</label>
        <select id="delivery-method" name="delivery_method" required>
          <option value="PICKUP"<?php echo ($manualOrderValues['delivery_method'] ?? '') === 'PICKUP' ? ' selected' : ''; ?>>Pickup</option>
          <option value="DELIVERY"<?php echo ($manualOrderValues['delivery_method'] ?? '') === 'DELIVERY' ? ' selected' : ''; ?>>Delivery</option>
        </select>
      </div>
      <div class="admin-field">
        <label for="required-date">Required date</label>
        <input id="required-date" name="required_date" type="date" required value="<?php echo $escape($manualOrderValues['required_date'] ?? ''); ?>">
      </div>
      <div class="admin-field">
        <label for="delivery-fee">Delivery fee (TND)</label>
        <input id="delivery-fee" name="delivery_fee" inputmode="decimal" pattern="[0-9]+(?:\.[0-9]{1,3})?" required value="<?php echo $escape($manualOrderValues['delivery_fee'] ?? '0.000'); ?>">
        <small>Pickup: 0.000. Delivery: at least 9.000.</small>
      </div>
      <div class="admin-field admin-field--wide" data-account-address-choice<?php echo $isNewAccount && $isDelivery ? '' : ' hidden'; ?>>
        <label class="admin-account-address-copy"><input type="checkbox" name="copy_account_address" value="1" data-copy-account-address<?php echo $isNewAccount ? '' : ' disabled'; ?><?php echo ($manualOrderValues['copy_account_address'] ?? '1') === '1' ? ' checked' : ''; ?>> Use the account primary address for this order's delivery</label>
      </div>
      <div class="admin-field admin-field--wide" data-order-address-heading<?php echo $showOrderAddress ? '' : ' hidden'; ?>><h3>Order delivery address</h3></div>
      <div class="admin-field admin-field--wide"<?php echo $showOrderAddress ? '' : ' hidden'; ?>>
        <label for="street-address">Street address</label>
        <input id="street-address" name="street_address" maxlength="255" value="<?php echo $escape($manualOrderValues['street_address'] ?? ''); ?>"<?php echo $showOrderAddress ? ' required' : ' disabled'; ?>>
      </div>
      <div class="admin-field"<?php echo $showOrderAddress ? '' : ' hidden'; ?>>
        <label for="delegation">Delegation</label>
        <input id="delegation" name="delegation" maxlength="100" value="<?php echo $escape($manualOrderValues['delegation'] ?? ''); ?>"<?php echo $showOrderAddress ? ' required' : ' disabled'; ?>>
      </div>
      <div class="admin-field"<?php echo $showOrderAddress ? '' : ' hidden'; ?>>
        <label for="governorate">Governorate</label>
        <input id="governorate" name="governorate" maxlength="100" value="<?php echo $escape($manualOrderValues['governorate'] ?? ''); ?>"<?php echo $showOrderAddress ? ' required' : ' disabled'; ?>>
      </div>
      <div class="admin-field"<?php echo $showOrderAddress ? '' : ' hidden'; ?>>
        <label for="postal-code">Postal code</label>
        <input id="postal-code" name="postal_code" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" value="<?php echo $escape($manualOrderValues['postal_code'] ?? ''); ?>"<?php echo $showOrderAddress ? ' required' : ' disabled'; ?>>
      </div>
    </div>
  </fieldset>

  <fieldset><legend>Discounts</legend>
    <div class="admin-form-grid">
      <div class="admin-field admin-field--wide"><label for="order-promo">Promo / collaboration code</label><div class="admin-order-customer-picker__search"><input id="order-promo" name="promo_code" maxlength="40" value="<?php echo $escape($manualOrderValues['promo_code'] ?? ''); ?>"><button class="admin-button admin-button--secondary" type="submit" name="action" value="review_pricing" formnovalidate data-apply-promo>Apply</button><button class="admin-button admin-button--secondary" type="submit" name="action" value="remove_promo" formnovalidate data-remove-promo>Remove</button></div><small><a href="<?php echo BASE_URL . 'admin/catalogue/promotions.php'; ?>">Manage promotion codes</a></small></div>
      <div class="admin-field"><label for="manual-discount-type">Additional admin discount</label><select id="manual-discount-type" name="manual_discount_type"><?php foreach (['NONE' => 'None', 'PERCENTAGE' => 'Percentage', 'FIXED_TND' => 'Fixed TND'] as $type => $label): ?><option value="<?php echo $escape($type); ?>"<?php echo ($manualOrderValues['manual_discount_type'] ?? 'NONE') === $type ? ' selected' : ''; ?>><?php echo $escape($label); ?></option><?php endforeach; ?></select></div>
      <div class="admin-field"><label for="manual-discount-value">Discount value</label><input id="manual-discount-value" name="manual_discount_value" inputmode="decimal" value="<?php echo $escape($manualOrderValues['manual_discount_value'] ?? ''); ?>"></div>
      <div class="admin-field admin-field--wide"><label for="manual-discount-reason">Discount reason (optional)</label><input id="manual-discount-reason" name="manual_discount_reason" maxlength="500" value="<?php echo $escape($manualOrderValues['manual_discount_reason'] ?? ''); ?>"></div>
    </div>
  </fieldset>
  <section class="admin-order-price-summary" aria-labelledby="order-summary-title"><h2 id="order-summary-title">Order summary</h2>
    <p data-price-error role="status"><?php echo !$manualOrderPricing['success'] ? $escape($manualOrderPricing['error'] ?? 'Select items and review prices.') : ''; ?></p>
    <dl class="admin-money-list">
      <?php foreach (['subtotal' => 'Items subtotal', 'savings' => 'Quantity savings (included above)', 'promo' => 'Promo discount', 'manual' => 'Manual discount', 'delivery' => 'Delivery', 'total' => 'Final total'] as $key => $label): ?>
        <?php $amount = $key === 'savings' ? ($manualOrderPricing['savings'] ?? '0.000') : ($manualOrderPricing['amounts'][$key] ?? '0.000'); ?>
        <div data-summary-row="<?php echo $escape($key); ?>"<?php echo in_array($key, ['savings','promo','manual'], true) && $amount === '0.000' ? ' hidden' : ''; ?>><dt><?php echo $escape($label); ?><?php if ($key === 'promo'): ?> <span data-promo-label><?php echo $escape($manualOrderPricing['promo_code'] ?? ''); ?></span><small data-promo-description><?php echo $escape($manualOrderPricing['promo_description'] ?? ''); ?></small><?php endif; ?></dt><dd data-summary-amount="<?php echo $escape($key); ?>"><?php echo $escape($amount . ' TND'); ?></dd></div>
      <?php endforeach; ?>
    </dl>
    <button class="admin-button admin-button--secondary" type="submit" name="action" value="review_pricing" formnovalidate>Review prices</button><p class="admin-customer-mode-help">Prices are confirmed again when you create the order.</p>
  </section>

  <fieldset><legend>Internal handoff</legend><div class="admin-field"><label for="administrator-notes">Administrator notes</label><textarea id="administrator-notes" name="administrator_notes" maxlength="10000" rows="5"><?php echo $escape($manualOrderValues['administrator_notes'] ?? ''); ?></textarea><small>Visible only to administrators.</small></div></fieldset>
  <div class="admin-conversion-form__actions"><p>Totals, any required 40% deposit, and each item's production cost are calculated on the server. The order, all items, their recipes, and the initial audit entry are saved together.</p><button class="admin-button" type="submit">Create manual order</button></div>
</form>
<script src="<?php echo BASE_URL . 'assets/js/admin-order-details.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin-order-details.js'); ?>" defer></script>

<template id="manual-order-item-template">
  <?php $renderItem(null, $blankItem); ?>
</template>
<?php // JSON_HEX_* flags keep the data inert inside the HTML document. ?>
<script type="application/json" id="manual-order-catalogue"><?php echo json_encode($catalogueJson, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script src="<?php echo BASE_URL . 'assets/js/admin-manual-order.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin-manual-order.js'); ?>" defer></script>
