<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Page view for Admin Order Detail; it turns prepared application data into HTML.
 *
 * DATA FLOW: An HTTP entry point loads a bootstrap, obtains data from a controller, selects this file as $contentView, and loads a layout. Layout variables such as $escape are therefore injected into this view scope.
 */

/**
 * @var array $orderDetail
 * @var object $backUrl
 * @var callable $escape
 * @var object $csrf
 * @var array|null $orderProduction From OrderProductionService::orderProduction().
 */


$formatMoney = static fn(mixed $amount): string => number_format((float) $amount, 3) . ' TND';
$formatDate = static function (mixed $value, bool $withTime = false): string {
  if (!is_string($value) || $value === '') {
    return 'Not set';
  }
  try {
    return (new DateTimeImmutable($value))->format($withTime ? 'd M Y, H:i' : 'd M Y');
  } catch (Throwable) {
    return 'Not set';
  }
};
$humanize = static fn(mixed $value): string => ucwords(strtolower(str_replace('_', ' ', (string) $value)));
$orderTransition ??= null;
$transitionNotice ??= ['errors' => [], 'values' => [], 'success' => null];
// Production data is optional so older callers (and tests) can render the page without it.
$orderProduction ??= null;
$recipeNotice ??= ['errors' => [], 'values' => [], 'success' => null];
$productionLabels = [
  'CATALOGUE' => ['Catalogue recipe', 'success'],
  'SET' => ['Set component recipes', 'success'],
  'CUSTOM' => ['Custom recipe', 'info'],
  'MISSING' => ['Missing production data', 'danger'],
];
?>
<?php if (!is_array($orderDetail['order'])): ?>
  <section class="admin-panel admin-detail-error" aria-labelledby="order-error-title">
    <div class="admin-empty-state" role="alert">
      <span class="admin-empty-state__mark admin-empty-state__mark--error" aria-hidden="true">!</span>
      <h1 id="order-error-title"><?php echo (int) $orderDetail['httpStatus'] === 404 ? 'Order not found' : 'Unable to show order'; ?></h1>
      <p><?php echo $escape($orderDetail['loadError']); ?></p>
      <a class="admin-button admin-button--secondary" href="<?php echo BASE_URL . 'admin/orders/index.php'; ?>">Back to orders</a>
    </div>
  </section>
<?php else: ?>
  <?php
  $order = $orderDetail['order'];
  // COLLECTION IDIOM: Keep only array items for which the callback returns true.
  $addressParts = array_filter([
    $order['street_address_snapshot'],
    $order['delegation_snapshot'],
    $order['governorate_snapshot'],
    $order['postal_code_snapshot'],
  ], static fn(mixed $value): bool => is_string($value) && trim($value) !== '');
  // URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
  $printUrl = BASE_URL . 'admin/orders/print.php?' . http_build_query(['id' => $order['id']]);
  ?>
  <header class="admin-order-header">
    <div>
      <a class="admin-back-link" href="<?php echo $escape($backUrl); ?>">← Back to orders</a>
      <p class="admin-eyebrow">Order record</p>
      <div class="admin-order-header__title">
        <h1><?php echo $escape($order['order_number']); ?></h1>
        <?php if (!empty($orderStatusOptions)): ?>
          <?php
          $statusControlOrder = $order;
          $statusControlOptions = $orderStatusOptions;
          $statusControlReturn = $orderDetail['backQuery'];
          $statusControlDestination = 'detail';
          $statusControlReason = $orderTransition['reason'] ?? 'No further status changes are available.';
          require __DIR__ . '/../../Components/Admin/order-status-select.php';
          ?>
        <?php else: ?>
          <?php $status = $order['order_status'];
          require __DIR__ . '/../../Components/Admin/status-badge.php'; ?>
        <?php endif; ?>
        <a class="admin-button admin-button--secondary" href="<?php echo $escape(BASE_URL . 'admin/orders/edit.php?' . http_build_query(['id' => $order['id'], 'return' => $orderDetail['backQuery']])); ?>">Edit order</a>
      </div>
      <p>Created <?php echo $escape($formatDate($order['created_at'], true)); ?> · <?php echo $escape($humanize($order['order_source'])); ?> order · Timing: <?php echo $escape($order['timing']['label']); ?></p>
    </div>
    <div class="admin-order-header__actions">
      <a class="admin-button admin-button--secondary" href="<?php echo $escape($printUrl); ?>">Print preparation summary</a>
      <a class="admin-button admin-button--secondary" href="<?php echo $escape(BASE_URL . 'admin/orders/delete.php?id=' . $order['id']); ?>">Delete order</a>
    </div>
  </header>

  <?php if (isset($editNotice) && is_string($editNotice['success'])): ?>
    <?php
    $alertTone = 'success';
    $alertTitle = 'Order updated';
    $alertMessage = $editNotice['success'];
    require __DIR__ . '/../../Components/Admin/alert.php';
    ?>
  <?php endif; ?>

  <?php if (is_string($transitionNotice['success'])): ?>
    <?php
    $alertTone = 'success';
    $alertTitle = 'Order status updated';
    $alertMessage = $transitionNotice['success'];
    require __DIR__ . '/../../Components/Admin/alert.php';
    ?>
  <?php endif; ?>

  <?php if ($transitionNotice['errors'] !== []): ?>
    <?php
    $alertTone = 'error';
    $alertTitle = 'Status not updated';
    $alertMessage = implode(' ', array_unique(array_values($transitionNotice['errors'])));
    require __DIR__ . '/../../Components/Admin/alert.php';
    ?>
  <?php endif; ?>

  <div class="admin-detail-grid admin-detail-grid--summary">
    <section class="admin-panel admin-detail-card" aria-labelledby="customer-title">
      <header class="admin-panel__header">
        <h2 id="customer-title">Customer snapshot</h2>
      </header>
      <dl class="admin-definition-list">
        <div>
          <dt>Name</dt>
          <dd><?php echo $escape($order['customer_name_snapshot']); ?></dd>
        </div>
        <div>
          <dt>Phone</dt>
          <dd><?php echo $escape($order['customer_phone_snapshot']); ?></dd>
        </div>
        <div>
          <dt>Email</dt>
          <dd><?php echo $escape($order['customer_email_snapshot'] ?: 'Not provided'); ?></dd>
        </div>
        <div>
          <dt>Account</dt>
          <dd><?php if ($order['customer_id'] === null): ?>Guest order — no account linked<?php else: ?><a href="<?php echo $escape(BASE_URL . 'admin/customers/detail.php?id=' . $order['customer_id']); ?>">View linked customer account and orders</a><?php endif; ?></dd>
        </div>
      </dl>
    </section>

    <section class="admin-panel admin-detail-card" aria-labelledby="fulfilment-title">
      <header class="admin-panel__header">
        <h2 id="fulfilment-title">Fulfilment snapshot</h2>
      </header>
      <dl class="admin-definition-list">
        <div>
          <dt>Method</dt>
          <dd><?php echo $escape($humanize($order['delivery_method'])); ?></dd>
        </div>
        <div>
          <dt>Required date</dt>
          <dd><time datetime="<?php echo $escape($order['required_date']); ?>"><?php echo $escape($formatDate($order['required_date'])); ?></time></dd>
        </div>
        <div class="admin-definition-list__wide">
          <dt>Delivery address</dt>
          <dd><?php echo $escape($addressParts === [] ? 'Not required or not provided' : implode(', ', $addressParts)); ?></dd>
        </div>
      </dl>
    </section>

    <section class="admin-panel admin-detail-card" aria-labelledby="financial-title">
      <header class="admin-panel__header">
        <h2 id="financial-title">Financial summary</h2>
      </header>
      <dl class="admin-money-list">
        <div>
          <dt>Subtotal</dt>
          <dd><?php echo $escape($formatMoney($order['subtotal'])); ?></dd>
        </div>
        <?php if ((float) ($order['quantity_savings_snapshot'] ?? 0) > 0): ?>
        <div><dt>Quantity savings (included in subtotal)</dt><dd><?php echo $escape($formatMoney($order['quantity_savings_snapshot'])); ?></dd></div>
        <?php endif; ?>
        <?php if ($order['promo_code_snapshot'] ?? null): ?>
        <div><dt>Promo <?php echo $escape($order['promo_code_snapshot']); ?></dt><dd>-<?php echo $escape($formatMoney($order['promo_discount_amount'])); ?></dd></div>
        <?php endif; ?>
        <?php if ($order['manual_discount_type'] ?? null): ?>
        <div><dt>Manual discount (<?php echo $escape($order['manual_discount_value']); ?> <?php echo $order['manual_discount_type'] === 'PERCENTAGE' ? '%' : 'TND'; ?>)</dt><dd>-<?php echo $escape($formatMoney($order['manual_discount_amount'])); ?><?php if ($order['manual_discount_reason']): ?><small><?php echo $escape($order['manual_discount_reason']); ?></small><?php endif; ?></dd></div>
        <?php endif; ?>
        <div>
          <dt>Delivery fee</dt>
          <dd><?php echo $escape($formatMoney($order['delivery_fee'])); ?></dd>
        </div>
        <div class="admin-money-list__total">
          <dt>Total</dt>
          <dd><?php echo $escape($formatMoney($order['total_amount'])); ?></dd>
        </div>
        <div>
          <dt>Required deposit</dt>
          <dd><?php echo $escape($formatMoney($order['required_deposit_amount'])); ?></dd>
        </div>
        <div>
          <dt>Verified amount paid</dt>
          <dd><?php echo $escape($formatMoney($order['amount_paid'])); ?></dd>
        </div>
        <div class="admin-money-list__balance">
          <dt>Pending verification</dt>
          <dd><?php echo $escape($formatMoney($order['pending_amount'])); ?></dd>
        </div>
        <div>
          <dt>Rejected amount</dt>
          <dd><?php echo $escape($formatMoney($order['rejected_amount'])); ?></dd>
        </div>
        <div>
          <dt>Payment status</dt>
          <dd><?php echo $escape($order['payment_status_label']); ?></dd>
        </div>
        <div><dt>Recorded payment methods</dt><dd><?php echo $escape($order['payment_methods_label']); ?></dd></div>
        <div class="admin-money-list__balance">
          <dt>Remaining balance</dt>
          <dd><?php echo $escape($formatMoney($order['remaining_balance'])); ?></dd>
        </div>
        <?php if ((float) ($order['credit_balance'] ?? 0) > 0): ?><div>
            <dt>Customer credit / refund due</dt>
            <dd><?php echo $escape($formatMoney($order['credit_balance'])); ?></dd>
          </div><?php endif; ?>
      </dl>
    </section>
  </div>

  <section class="admin-panel admin-panel--table" aria-labelledby="order-items-title">
    <header class="admin-panel__header">
      <div>
        <p class="admin-eyebrow">Stored snapshots</p>
        <h2 id="order-items-title">Order items</h2>
      </div>
      <span class="admin-panel__count"><?php echo $escape(count($orderDetail['items'])); ?></span>
    </header>
    <?php if ($orderDetail['items'] === []): ?>
      <?php $emptyTitle = 'No order items';
      $emptyMessage = 'This historical order has no item rows.';
      require __DIR__ . '/../../Components/Admin/empty-state.php'; ?>
    <?php else: ?>
      <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable order items table">
        <table class="admin-table admin-items-table">
          <caption class="admin-visually-hidden">Items recorded for this order</caption>
          <thead>
            <tr>
              <th scope="col">Item snapshot</th>
              <th scope="col">Options</th>
              <th scope="col">Quantity</th>
              <th scope="col">Unit price</th>
              <th scope="col">Line total</th>
              <th scope="col">Unit cost</th>
              <th scope="col">Customization</th>
              <?php if (is_array($orderProduction)): ?><th scope="col">Production</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orderDetail['items'] as $item): ?>
              <tr>
                <td>
                  <strong><?php echo $escape($item['product_name_snapshot']); ?></strong>
                  <?php if ($item['product_id'] === null): ?>
                    <small>Not linked to a catalogue product</small>
                  <?php endif; ?>
                </td>
                <?php // COLLECTION IDIOM: array_filter removes empty option labels. 
                ?>
                <td><?php echo $escape(implode(' · ', array_filter([$item['color_name_snapshot'], $item['scent_name_snapshot']])) ?: 'No color or scent'); ?></td>
                <td><?php echo $escape($item['quantity']); ?></td>
                <td><?php echo $escape($formatMoney($item['unit_price_snapshot'])); ?>
                  <?php if ($item['catalogue_unit_price_snapshot'] ?? null): ?><small>Catalogue: <?php echo $escape($formatMoney($item['catalogue_unit_price_snapshot'])); ?></small><?php endif; ?>
                  <?php if ($item['quantity_tier_snapshot'] ?? null): ?><small>Tier <?php echo $escape($item['quantity_tier_snapshot']); ?>: <?php echo $escape($formatMoney($item['quantity_unit_price_snapshot'])); ?></small><?php endif; ?>
                  <?php if ($item['price_override_reason'] ?? null): ?><small>Override: <?php echo $escape($item['price_override_reason']); ?></small><?php endif; ?>
                </td>
                <td><?php echo $escape($formatMoney($item['line_total_snapshot'])); ?></td>
                <td><?php echo $escape($item['unit_cost_snapshot'] === null ? 'Not recorded' : $formatMoney($item['unit_cost_snapshot'])); ?></td>
                <td><?php echo $escape($item['customization_notes'] ?: 'None'); ?></td>
                <?php if (is_array($orderProduction)): ?>
                  <?php $itemProduction = $orderProduction['items'][(int) $item['id']] ?? ['source' => 'MISSING', 'reason' => null, 'lines' => []]; ?>
                  <td>
                    <span class="admin-badge admin-badge--<?php echo $escape($productionLabels[$itemProduction['source']][1]); ?>"><?php echo $escape($productionLabels[$itemProduction['source']][0]); ?></span>
                    <?php if ($itemProduction['reason'] !== null): ?><small><?php echo $escape(ucfirst($itemProduction['reason'])); ?></small><?php endif; ?>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <?php if (is_array($orderProduction) && $orderDetail['items'] !== []): ?>
    <!-- SECTION PURPOSE: Show how each item is produced and let an administrator add explicit custom recipes before preparation. -->
    <section class="admin-panel admin-order-production" aria-labelledby="order-production-title">
      <header class="admin-panel__header">
        <div>
          <p class="admin-eyebrow">Materials used at preparation</p>
          <h2 id="order-production-title">Production recipes</h2>
        </div>
      </header>

      <?php if (is_string($recipeNotice['success'])): ?>
        <?php $alertTone = 'success';
        $alertTitle = 'Recipe saved';
        $alertMessage = $recipeNotice['success'];
        require __DIR__ . '/../../Components/Admin/alert.php'; ?>
      <?php endif; ?>
      <?php if ($recipeNotice['errors'] !== []): ?>
        <?php $alertTone = 'error';
        $alertTitle = 'Recipe not saved';
        $alertMessage = implode(' ', array_unique(array_values($recipeNotice['errors'])));
        require __DIR__ . '/../../Components/Admin/alert.php'; ?>
      <?php endif; ?>
      <?php if ($orderProduction['missingCount'] > 0): ?>
        <?php
        $alertTone = 'warning';
        $alertTitle = 'Preparation is blocked';
        $alertMessage = $orderProduction['missingCount'] . ' item(s) have no saved production recipe. '
          . 'Confirm the historical or agreed custom recipe below before moving the order to Preparing.';
        require __DIR__ . '/../../Components/Admin/alert.php';
        ?>
      <?php endif; ?>

      <?php foreach ($orderDetail['items'] as $item): ?>
        <?php $itemProduction = $orderProduction['items'][(int) $item['id']] ?? ['source' => 'MISSING', 'reason' => null, 'lines' => []]; ?>
        <article class="admin-order-production__item">
          <h3><?php echo $escape($item['product_name_snapshot']); ?> <small>× <?php echo $escape($item['quantity']); ?></small></h3>
          <?php if ($itemProduction['lines'] !== []): ?>
            <ul class="admin-order-production__lines">
              <?php foreach ($itemProduction['lines'] as $line): ?>
                <li><?php echo $escape($line['material_name_snapshot']); ?>: <?php echo $escape($line['quantity_required'] . ' ' . $line['unit_snapshot']); ?> per unit
                  <small>(<?php echo $escape($formatMoney($line['unit_cost_snapshot'])); ?> / <?php echo $escape($line['unit_snapshot']); ?>)</small>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php elseif ($itemProduction['source'] === 'MISSING'): ?>
            <p>No production data: <?php echo $escape($itemProduction['reason'] ?? 'unknown'); ?>.</p>
          <?php else: ?>
            <p>Made from the current catalogue recipe.</p>
          <?php endif; ?>

          <?php if ($orderProduction['editable'] && empty($itemProduction['snapshot'])): ?>
            <?php // Existing lines plus three blank rows; blank rows are ignored by the server. 
            ?>
            <?php $formLines = array_merge($itemProduction['lines'], array_fill(0, 3, ['material_id' => '', 'quantity_required' => ''])); ?>
            <details<?php echo $itemProduction['source'] === 'MISSING' ? ' open' : ''; ?>>
              <summary><?php echo $itemProduction['lines'] === [] ? 'Add a custom recipe' : 'Edit the custom recipe'; ?></summary>
              <form class="admin-order-production__form" action="<?php echo BASE_URL . 'admin/orders/item-recipe.php'; ?>" method="post">
                <!-- CSRF binds this state-changing submission to the administrator session. -->
                <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
                <input type="hidden" name="order_id" value="<?php echo $escape($order['id']); ?>">
                <input type="hidden" name="item_id" value="<?php echo $escape($item['id']); ?>">
                <?php foreach ($formLines as $lineIndex => $line): ?>
                  <div class="admin-form-grid">
                    <div class="admin-field">
                      <label for="recipe-<?php echo $escape($item['id'] . '-' . $lineIndex); ?>-material">Material</label>
                      <select id="recipe-<?php echo $escape($item['id'] . '-' . $lineIndex); ?>-material" name="recipe[<?php echo $escape($lineIndex); ?>][material_id]">
                        <option value="">—</option>
                        <?php foreach ($orderProduction['materials'] as $material): ?>
                          <option value="<?php echo $escape($material['id']); ?>" <?php echo (string) $line['material_id'] === (string) $material['id'] ? ' selected' : ''; ?>><?php echo $escape($material['name'] . ' (' . $material['unit'] . ')'); ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="admin-field">
                      <label for="recipe-<?php echo $escape($item['id'] . '-' . $lineIndex); ?>-quantity">Quantity per unit</label>
                      <input id="recipe-<?php echo $escape($item['id'] . '-' . $lineIndex); ?>-quantity" name="recipe[<?php echo $escape($lineIndex); ?>][quantity]" inputmode="decimal" pattern="[0-9]+(?:\.[0-9]{1,3})?" value="<?php echo $escape($line['quantity_required']); ?>">
                    </div>
                  </div>
                <?php endforeach; ?>
                <p><small><?php echo $item['product_id'] === null
                            ? 'This item is not linked to a catalogue product, so it needs at least one material.'
                            : 'Leave every row empty to use the catalogue recipe again.'; ?> The item cost is recalculated from the recipe.</small></p>
                <button class="admin-button admin-button--secondary" type="submit">Save recipe</button>
              </form>
              </details>
            <?php endif; ?>
        </article>
      <?php endforeach; ?>
      <?php if (!$orderProduction['editable']): ?>
        <p><small>Recipes are frozen once the order has been prepared; they explain the materials recorded in stock history.</small></p>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <div class="admin-detail-grid">
    <section class="admin-panel admin-panel--table" aria-labelledby="payment-history-title">
      <?php // URL IDIOM: http_build_query safely encodes each query-string value. 
      ?>
      <header class="admin-panel__header">
        <div>
          <p class="admin-eyebrow">Read-only</p>
          <h2 id="payment-history-title">Payments</h2>
        </div>
        <a class="admin-button admin-button--secondary" href="
          <?php echo $escape(BASE_URL . 'admin/payments/order.php?' . http_build_query(['order_id' => $order['id']])); ?>">Manage payments</a>
      </header>
      <?php if ($orderDetail['payments'] === []): ?>
        <?php $emptyTitle = 'No payments recorded';
        $emptyMessage = 'Payment records linked to this order will appear here.';
        require __DIR__ . '/../../Components/Admin/empty-state.php'; ?>
      <?php else: ?>
        <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable payment history table">
          <table class="admin-table">
            <caption class="admin-visually-hidden">Payment history</caption>
            <thead>
              <tr>
                <th scope="col">Type</th>
                <th scope="col">Method</th>
                <th scope="col">Amount</th>
                <th scope="col">Status</th>
                <th scope="col">Recorded</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($orderDetail['payments'] as $payment): ?>
                <tr>
                  <td><?php echo $escape($humanize($payment['payment_type'])); ?></td>
                  <td><?php echo $escape($payment['payment_method'] === 'CASH_ON_DELIVERY' ? 'Pay on delivery / pickup' : $payment['payment_method']); ?></td>
                  <td><?php echo $escape($formatMoney($payment['amount'])); ?></td>
                  <td><?php $status = $payment['payment_status'];
                      require __DIR__ . '/../../Components/Admin/status-badge.php'; ?></td>
                  <td><?php echo $escape($formatDate($payment['created_at'], true)); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="admin-panel" aria-labelledby="status-history-title">
      <header class="admin-panel__header">
        <div>
          <h2 id="status-history-title">Status history</h2>
        </div>
      </header>
      <?php if ($orderDetail['history'] === []): ?>
        <?php $emptyTitle = 'No status history';
        $emptyMessage = 'No status changes have been recorded for this order.';
        require __DIR__ . '/../../Components/Admin/empty-state.php'; ?>
      <?php else: ?>
        <ol class="admin-timeline">
          <?php foreach ($orderDetail['history'] as $history): ?>
            <li>
              <span class="admin-timeline__mark" aria-hidden="true"></span>
              <div>
                <strong><?php echo $escape($humanize($history['new_status'])); ?></strong>
                <span><?php echo $escape($formatDate($history['changed_at'], true)); ?> · <?php echo $escape($history['administrator_name'] ?: $humanize($history['change_source'])); ?></span>
                <?php if ($history['change_note']): ?>
                  <p><?php echo $escape($history['change_note']); ?></p>
                <?php endif; ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>
    </section>
  </div>

  <section class="admin-panel admin-notes" aria-labelledby="order-notes-title">
    <header class="admin-panel__header">
      <h2 id="order-notes-title">Order notes</h2>
    </header>
    <div class="admin-notes__grid">
      <div>
        <h3>Customer notes</h3>
        <p><?php echo nl2br($escape($order['customer_notes'] ?: 'No customer notes.')); ?></p>
      </div>
      <div>
        <h3>Administrator notes</h3>
        <p><?php echo nl2br($escape($order['administrator_notes'] ?: 'No administrator notes.')); ?></p>
      </div>
    </div>
  </section>
<?php endif; ?>
<script src="<?php echo BASE_URL . 'assets/js/admin-order-status.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin-order-status.js'); ?>" defer></script>
