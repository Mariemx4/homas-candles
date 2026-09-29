<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Reusable view fragment for Customer Addresses.
 *
 * DATA FLOW: A parent view prepares the variables this fragment expects and requires it in the same PHP scope. This is a partial, not a page that should be opened directly.
 */
?>
<!-- SECTION PURPOSE: Group the Saved addresses content, states, and controls as one accessible page region. -->
<section class="admin-panel admin-customer-addresses" aria-labelledby="customer-addresses-title">
  <header class="admin-panel__header"><div><h2 id="customer-addresses-title">Saved addresses</h2></div><span class="admin-panel__meta"><?php echo $escape(count($customerDetail['addresses'])); ?> saved</span></header>
  <?php if ($customerDetail['addresses'] === []): ?>
    <?php $emptyTitle = 'No saved addresses'; $emptyMessage = 'This account currently has no address records.'; require __DIR__ . '/empty-state.php'; ?>
  <?php else: ?>
    <div class="admin-address-grid">
      <?php foreach ($customerDetail['addresses'] as $address): ?>
        <article class="admin-address-card">
          <header><h3><?php echo $escape($address['address_label'] ?? 'Saved address'); ?></h3><?php if ((int) $address['is_primary'] === 1): ?><span class="admin-badge admin-badge--success">Primary</span><?php endif; ?></header>
          <address><?php echo $escape($address['street_address']); ?><br><?php echo $escape($address['delegation']); ?>, <?php echo $escape($address['governorate']); ?><br><?php echo $escape($address['postal_code']); ?></address>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
