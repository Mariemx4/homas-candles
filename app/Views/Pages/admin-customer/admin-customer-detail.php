<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Page view for Admin Customer Detail; it turns prepared application data into HTML.
 *
 * DATA FLOW: An HTTP entry point loads a bootstrap, obtains data from a controller, selects this file as $contentView, and loads a layout. Layout variables such as $escape are therefore injected into this view scope.
 */

$humanize = static fn(mixed $value): string => ucwords(strtolower(str_replace('_', ' ', (string) $value)));
$formatDateTime = static function (mixed $value, string $fallback = 'Not recorded'): string {
  if (!is_string($value) || $value === '') {
    return $fallback;
  }
  try {
    return (new DateTimeImmutable($value))->format('d M Y, H:i');
  } catch (Throwable) {
    return $fallback;
  }
};
$formatMoney = static fn(mixed $value): string => number_format((float) $value, 3) . ' TND';
?>
<?php if (!is_array($customerDetail)): ?>
  <section class="admin-panel admin-detail-error">
    <?php
    $emptyTitle = $customerResult['httpStatus'] === 404 ? 'Customer not found' : 'Customer unavailable';
    $emptyMessage = is_string($customerResult['loadError'])
      ? $customerResult['loadError']
      : 'Return to the protected directory and search for an available account.';
    require __DIR__ . '/../../Components/Admin/empty-state.php';
    ?>
    <a class="admin-button" href="<?php echo $escape($backUrl); ?>">Back to customers</a>
  </section>
<?php else: $customer = $customerDetail['customer']; ?>
  <header class="admin-page-header admin-page-header--detail">
    <div>
      <p class="admin-eyebrow">Registered customer</p>
      <h1><?php echo $escape(trim((string) $customer['first_name'] . ' ' . (string) $customer['last_name'])); ?></h1>
      <p class="admin-page-header__summary">
        Account data and immutable transaction snapshots are shown separately below.
      </p>
    </div>
    <div class="admin-detail-actions">
      <a class="admin-button admin-button--secondary" href="<?php echo $escape($backUrl); ?>">Back to directory</a>
      <?php // URL IDIOM: http_build_query safely encodes each query-string value. 
      ?>
      <!-- A manual order is already agreed work; it should start in Orders, not in the enquiry inbox. -->
      <a class="admin-button" href="<?php echo $escape(BASE_URL . 'admin/orders/create.php?' . http_build_query(['customer_id' => $customer['id']])); ?>">
        Create manual order
      </a>
    </div>
  </header>

  <?php if ($customerDetail['pagesAdjusted']): ?>
    <?php $alertTone = 'warning';
    $alertTitle = 'History page adjusted';
    $alertMessage = 'An invalid history page was reset safely.';
    require __DIR__ . '/../../Components/Admin/alert.php'; ?>
  <?php endif; ?>

  <?php require __DIR__ . '/../../Components/Admin/customer-account-summary.php'; ?>
  <?php require __DIR__ . '/../../Components/Admin/customer-addresses.php'; ?>
  <?php require __DIR__ . '/../../Components/Admin/customer-order-history.php'; ?>
  <?php require __DIR__ . '/../../Components/Admin/customer-request-history.php'; ?>
<?php endif; ?>