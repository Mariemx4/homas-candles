<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Reusable view fragment for Customer Account Summary.
 *
 * DATA FLOW: A parent view prepares the variables this fragment expects and requires it in the same PHP scope. This is a partial, not a page that should be opened directly.
 */

$metrics = $customerDetail['metrics'];
?>
<div class="admin-detail-grid admin-detail-grid--summary admin-customer-summary-grid">
  <section class="admin-panel admin-detail-card">
    <header class="admin-panel__header"><div><h2>Account data</h2></div><?php $status = $customer['account_status']; require __DIR__ . '/status-badge.php'; ?></header>
    <dl class="admin-definition-list">
      <div><dt>Email</dt><dd><?php echo $escape($customer['email']); ?></dd></div>
      <div><dt>Primary phone</dt><dd><?php echo $escape($customer['phone_number']); ?></dd></div>
      <div><dt>Second phone</dt><dd><?php echo $escape($customer['second_phone_number'] ?? 'Not provided'); ?></dd></div>
      <div><dt>Last login</dt><dd><?php echo $escape($formatDateTime($customer['last_login_at'], 'Never recorded')); ?></dd></div>
      <div><dt>Joined</dt><dd><?php echo $escape($formatDateTime($customer['created_at'])); ?></dd></div>
      <div><dt>Profile updated</dt><dd><?php echo $escape($formatDateTime($customer['updated_at'])); ?></dd></div>
    </dl>
    <p class="admin-customer-account-note">Account-state controls are withheld because the current schema has no administrator-attributed status history or immediate active-session revocation.</p>
  </section>

  <section class="admin-panel admin-detail-card">
    <header class="admin-panel__header"><div><h2>History totals</h2></div></header>
    <dl class="admin-money-list">
      <div><dt>Orders</dt><dd><?php echo $escape($metrics['order_count']); ?></dd></div>
      <div><dt>Custom requests</dt><dd><?php echo $escape($metrics['request_count']); ?></dd></div>
      <div><dt>Last order</dt><dd><?php echo $escape($formatDateTime($metrics['last_order_at'], 'No orders yet')); ?></dd></div>
      <div><dt>All verified payments</dt><dd><?php echo $escape($formatMoney($metrics['verified_amount'])); ?></dd></div>
      <div class="admin-money-list__total"><dt>Completed verified value</dt><dd><?php echo $escape($formatMoney($metrics['completed_verified_amount'])); ?></dd></div>
    </dl>
    <p class="admin-customer-metric-note">Completed verified value is the sum of <strong>VERIFIED</strong> payments attached to <strong>PICKED UP</strong> or <strong>DELIVERED</strong> orders. Pending and rejected payments are excluded.</p>
  </section>
</div>
