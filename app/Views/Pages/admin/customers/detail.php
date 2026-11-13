<?php

declare(strict_types=1);

use App\View\Presentation;
use App\View\AdminIcons;

$humanize = Presentation::humanize(...);
$formatDateTime = Presentation::timestamp(...);
$formatMoney = Presentation::money(...);
?>
<?php if (!is_array($customerDetail)): ?>
  <section class="admin-panel admin-detail-error">
    <?php
    $emptyTitle = $customerResult['httpStatus'] === 404 ? 'Customer not found' : 'Customer unavailable';
    $emptyMessage = is_string($customerResult['loadError'])
      ? $customerResult['loadError']
      : 'Return to the protected directory and search for an available account.';
    require VIEW_PATH . '/Components/Admin/empty-state.php';
    ?>
    <a class="admin-button" href="<?php echo $escape($backUrl); ?>"><?php echo AdminIcons::render('back'); ?>Back to customers</a>
  </section>
<?php else: $customer = $customerDetail['customer']; ?>
  <header class="admin-page-header admin-page-header--detail">
    <div>
      <p class="admin-eyebrow">Registered customer</p>
      <h1><?php echo $escape(trim((string) $customer['first_name'] . ' ' . (string) $customer['last_name'])); ?></h1>
      <p class="admin-page-header__summary">
        Contact details, addresses and your history with this customer.
      </p>
    </div>
    <div class="admin-detail-actions">
      <a class="admin-button admin-button--secondary" href="<?php echo $escape($backUrl); ?>"><?php echo AdminIcons::render('back'); ?>Back to directory</a>
      <?php // URL IDIOM: http_build_query safely encodes each query-string value.
      ?>
      <!-- A manual order is already agreed work; it should start in Orders, not in the enquiry inbox. -->
      <a class="admin-button" href="<?php echo $escape(BASE_URL . 'admin/orders/create.php?' . http_build_query(['customer_id' => $customer['id']])); ?>">
        Create manual order
      </a>
    </div>
  </header>
<div class="admin-conversion-form__actions"><a class="admin-button admin-button--secondary" href="<?php echo $escape(BASE_URL . 'admin/accounts.php?' . http_build_query(['kind' => 'customers','id' => $customer['id'],'return' => $customerResult['backQuery']])); ?>"><?php echo AdminIcons::render('edit'); ?>Edit current profile</a><a class="admin-button admin-button--secondary" href="<?php echo $escape(BASE_URL . 'admin/lifecycle.php?' . http_build_query(['kind' => 'customers','id' => $customer['id']])); ?>"><?php echo AdminIcons::render('settings'); ?>Account status</a></div>
<?php if (is_string($customerProfileNotice['success'] ?? null)): $alertTone = 'success';
    $alertTitle = 'Customer updated';
    $alertMessage = $customerProfileNotice['success'];
    require VIEW_PATH . '/Components/Admin/alert.php';endif; ?>


  <?php if ($customerDetail['pagesAdjusted']): ?>
    <?php $alertTone = 'warning';
      $alertTitle = 'History page adjusted';
      $alertMessage = 'An invalid history page was reset safely.';
      require VIEW_PATH . '/Components/Admin/alert.php'; ?>
  <?php endif; ?>

  <?php require VIEW_PATH . '/Components/Admin/customer-account-summary.php'; ?>
  <?php require VIEW_PATH . '/Components/Admin/customer-addresses.php'; ?>
  <?php require VIEW_PATH . '/Components/Admin/customer-order-history.php'; ?>
  <?php require VIEW_PATH . '/Components/Admin/customer-request-history.php'; ?>
<?php endif; ?>