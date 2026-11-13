<?php

declare(strict_types=1);

use App\View\AdminIcons;

$fieldError = static fn (string $key): string => isset($conversionFlash['errors'][$key]) ? (string) $conversionFlash['errors'][$key] : '';
?>
<?php if (!is_array($requestDetail)): ?>
  <?php $emptyTitle = 'Request unavailable';
    $emptyMessage = 'This request cannot be converted.';
    require VIEW_PATH . '/Components/Admin/empty-state.php'; ?>
<?php elseif ($requestResult['httpStatus'] !== 200): ?>
  <?php $emptyTitle = 'Conversion unavailable';
        $emptyMessage = 'Only an agreed, unconverted request can become a manual order.';
        require VIEW_PATH . '/Components/Admin/empty-state.php'; ?>
<?php else: $request = $requestDetail['request']; ?>
  <header class="admin-page-header admin-page-header--detail"><div><h1>Create manual order</h1><p class="admin-page-header__summary">Review the final agreement for <?php echo $escape($request['request_number']); ?>. Check the agreed products, prices, date and fulfilment details before creating the order.</p></div><a class="admin-button admin-button--secondary" href="<?php echo BASE_URL . 'admin/requests/detail.php?id=' . $escape($request['id']); ?>"><?php echo AdminIcons::render('back'); ?>Cancel</a></header>
  <?php if ($conversionFlash['errors'] !== []): ?><?php $alertTone = 'error';
      $alertTitle = 'Order not created';
      $alertMessage = implode(' ', array_values($conversionFlash['errors']));
      require VIEW_PATH . '/Components/Admin/alert.php'; ?><?php endif; ?>
  <form class="admin-panel admin-conversion-form" action="<?php echo BASE_URL . 'admin/requests/convert.php'; ?>" method="post" data-order-fulfilment data-conversion-form data-form-journey="conversion" data-delivery-fee-default="9.000">
    <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>"><input type="hidden" name="id" value="<?php echo $escape($request['id']); ?>">
    <?php if ($request['budget'] !== null): ?><p class="admin-panel__intro">Customer's requested total budget: <?php echo $escape($request['budget']); ?> TND. This is a preference, not an agreed price.</p><?php endif; ?>
    <?php if (!empty($request['custom_products'])): ?>
    <?php foreach ($request['custom_products'] as $index => $definition): $line = $conversionValues['custom_products'][$index] ?? []; ?>
    <fieldset><legend>Agreed Product <?php echo $escape($index + 1); ?></legend><p><?php echo $escape($definition['category_name'] . ' / ' . $definition['type_name']); ?></p>
    <input type="hidden" name="custom_products[<?php echo $escape($index); ?>][definition_id]" value="<?php echo $escape($definition['id']); ?>">
    <div class="admin-form-grid">
    <?php foreach (['product_name' => 'Product name','quantity' => 'Agreed quantity','agreed_batch_price' => 'Agreed batch price (TND)','unit_price' => 'Agreed unit price (TND)','customization_notes' => 'Final specifications'] as $key => $label): ?>
    <div class="admin-field"><label for="quote-<?php echo $escape($index . '-' . $key); ?>"><?php echo $escape($label); ?></label>
    <?php if ($key === 'customization_notes'): ?><textarea id="quote-<?php echo $escape($index . '-' . $key); ?>" name="custom_products[<?php echo $escape($index); ?>][<?php echo $escape($key); ?>]" maxlength="10000" rows="4"><?php echo $escape($line[$key] ?? ''); ?></textarea>
    <?php else: ?><input id="quote-<?php echo $escape($index . '-' . $key); ?>" name="custom_products[<?php echo $escape($index); ?>][<?php echo $escape($key); ?>]" value="<?php echo $escape($line[$key] ?? ''); ?>"<?php echo $key === 'quantity' ? ' type="number" min="' . ($definition['category_slug'] === 'event-favors' ? 10 : 1) . '" max="1000000" required' : ($key === 'product_name' ? ' maxlength="180" required' : ' inputmode="decimal"'); ?>><?php endif; ?>
    </div><?php endforeach; ?></div><p>Enter one agreed batch price or unit price for this product. Production requires a saved recipe before preparation.</p></fieldset>
    <?php endforeach; ?>
    <?php else: ?>
    <fieldset data-journey-step="0"><legend>Agreed item</legend><div class="admin-form-grid">
      <div class="admin-field admin-field--wide"><label for="product-name">Product name</label><input id="product-name" name="product_name" maxlength="180" required value="<?php echo $escape($conversionValues['product_name'] ?? ''); ?>"><?php if ($fieldError('product_name') !== ''): ?><small class="admin-field-error"><?php echo $escape($fieldError('product_name')); ?></small><?php endif; ?></div>
      <div class="admin-field"><label for="quantity">Quantity</label><input id="quantity" name="quantity" type="number" min="<?php echo $minimumQuantity; ?>" step="1" required value="<?php echo $escape($conversionValues['quantity'] ?? ''); ?>"><?php if ($fieldError('quantity') !== ''): ?><small class="admin-field-error"><?php echo $escape($fieldError('quantity')); ?></small><?php endif; ?></div>
      <div class="admin-field admin-field--wide"><p>Enter exactly one confirmed price. A batch price covers all items; a unit price is multiplied by the quantity.</p></div>
      <div class="admin-field"><label for="agreed-batch-price">Agreed batch price (TND)</label><input id="agreed-batch-price" name="agreed_batch_price" inputmode="decimal" pattern="[0-9]+(?:\.[0-9]{1,3})?" value="<?php echo $escape($conversionValues['agreed_batch_price'] ?? ''); ?>"><small>Must divide exactly by quantity to 0.001 TND per item.</small><?php if ($fieldError('agreed_batch_price') !== ''): ?><small class="admin-field-error"><?php echo $escape($fieldError('agreed_batch_price')); ?></small><?php endif; ?></div>
      <div class="admin-field"><label for="unit-price">Agreed unit price (TND)</label><input id="unit-price" name="unit_price" inputmode="decimal" pattern="[0-9]+(?:\.[0-9]{1,3})?" value="<?php echo $escape($conversionValues['unit_price'] ?? ''); ?>"><?php if ($fieldError('unit_price') !== ''): ?><small class="admin-field-error"><?php echo $escape($fieldError('unit_price')); ?></small><?php endif; ?></div>
      <div class="admin-field admin-field--wide"><label for="customization-notes">Final customization agreement</label><textarea id="customization-notes" name="customization_notes" maxlength="10000" rows="6"><?php echo $escape($conversionValues['customization_notes'] ?? ''); ?></textarea></div>
    </div></fieldset>
    <?php endif; ?>
    <fieldset data-journey-step="1"><legend>Fulfilment</legend><div class="admin-form-grid"><div class="admin-field"><label for="delivery-method">Method</label><select id="delivery-method" name="delivery_method" required><option value="PICKUP"<?php echo ($conversionValues['delivery_method'] ?? '') === 'PICKUP' ? ' selected' : ''; ?>>Pickup</option><option value="DELIVERY"<?php echo ($conversionValues['delivery_method'] ?? '') === 'DELIVERY' ? ' selected' : ''; ?>>Delivery</option></select></div><div class="admin-field"><label for="required-date">Required date</label><input id="required-date" name="required_date" type="date" required value="<?php echo $escape($conversionValues['required_date'] ?? ''); ?>"></div><div class="admin-field"><label for="delivery-fee">Delivery fee (TND)</label><input id="delivery-fee" name="delivery_fee" inputmode="decimal" required value="<?php echo $escape($conversionValues['delivery_fee'] ?? '0.000'); ?>"><small>Pickup: 0.000. Delivery: at least 9.000.</small></div><div class="admin-field admin-field--wide"><label for="street-address">Street address</label><input id="street-address" name="street_address" maxlength="255" value="<?php echo $escape($conversionValues['street_address'] ?? ''); ?>"></div><div class="admin-field"><label for="delegation">Delegation</label><input id="delegation" name="delegation" maxlength="100" value="<?php echo $escape($conversionValues['delegation'] ?? ''); ?>"></div><div class="admin-field"><label for="governorate">Governorate</label><input id="governorate" name="governorate" maxlength="100" value="<?php echo $escape($conversionValues['governorate'] ?? ''); ?>"></div><div class="admin-field"><label for="postal-code">Postal code</label><input id="postal-code" name="postal_code" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" value="<?php echo $escape($conversionValues['postal_code'] ?? ''); ?>"></div></div></fieldset>
    <fieldset data-journey-step="2"><legend>Internal handoff</legend><div class="admin-field"><label for="administrator-notes">Administrator notes</label><textarea id="administrator-notes" name="administrator_notes" maxlength="10000" rows="5"><?php echo $escape($conversionValues['administrator_notes'] ?? ''); ?></textarea><small>Protected operational notes; not customer-submitted content.</small></div></fieldset>
    <section data-journey-step="2" class="admin-panel" aria-labelledby="conversion-total-heading">
      <h2 id="conversion-total-heading">Review customer total</h2>
      <div data-conversion-quote aria-live="polite">
        <?php if (!empty($conversionPricing['success'])): $quote = $conversionPricing['totals']; ?>
        <dl class="admin-definition-list"><div><dt>Products</dt><dd><?php echo $escape($quote['subtotal']); ?> TND</dd></div><div><dt>Delivery</dt><dd><?php echo $escape($quote['delivery_fee']); ?> TND</dd></div><div><dt>Customer total</dt><dd><strong><?php echo $escape($quote['total']); ?> TND</strong></dd></div><div><dt>Required deposit</dt><dd><?php echo $escape($quote['deposit']); ?> TND</dd></div></dl>
        <?php else: ?><p>Complete the agreed prices and fulfilment details, then review the totals.</p><?php endif; ?>
      </div>
      <p>A 40% deposit is required when the total reaches 100.000 TND. Production costs are internal estimates and are not added to the agreed customer price.</p>
    </section>
    <input type="hidden" name="quote_total" value="<?php echo $escape($conversionValues['quote_total'] ?? ''); ?>">
    <input type="hidden" name="request_payload_complete" value="1">
    <div class="admin-conversion-form__actions" data-journey-step="2"><button class="admin-button admin-button--secondary" name="conversion_action" value="review" type="submit">Review totals</button><button class="admin-button" type="submit" data-conversion-create>Create one manual order</button></div>
  </form>
  <script src="<?php echo BASE_URL . 'assets/js/admin/order-details.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin/order-details.js'); ?>" defer></script>
  <script src="<?php echo BASE_URL . 'assets/js/admin/request-conversion.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin/request-conversion.js'); ?>" defer></script>
<?php endif; ?>
