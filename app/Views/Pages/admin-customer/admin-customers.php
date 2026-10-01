<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Page view for Admin Customers; it turns prepared application data into HTML.
 *
 * DATA FLOW: An HTTP entry point loads a bootstrap, obtains data from a controller, selects this file as $contentView, and loads a layout. Layout variables such as $escape are therefore injected into this view scope.
 */

$formatDate = static function (mixed $value): string {
    if (!is_string($value) || $value === '') {
        return 'No orders yet';
    }
    try {
        return (new DateTimeImmutable($value))->format('d M Y');
    } catch (Throwable) {
        return 'Not available';
    }
};
?>
<header class="admin-page-header admin-page-header--orders">
  <div>
    <h1>Customers</h1>
    <p class="admin-page-header__summary">Search registered accounts for legitimate order support and repeat business.</p>
  </div>
  <?php if (!$customerList['searchRequired']): ?>
    <div class="admin-result-summary" aria-live="polite">
      <strong><?php echo $escape($customerList['total']); ?><?php echo $customerList['capped'] ? '+' : ''; ?></strong>
      <span><?php echo (int) $customerList['total'] === 1 ? 'matching customer' : 'matching customers'; ?></span>
    </div>
  <?php endif; ?>
</header>

<?php if (is_string($customerList['loadError'])): ?>
  <?php $alertTone = 'error'; $alertTitle = 'Unable to load customers'; $alertMessage = $customerList['loadError']; require __DIR__ . '/../../Components/Admin/alert.php'; ?>
<?php elseif ($customerList['filtersAdjusted']): ?>
  <?php $alertTone = 'warning'; $alertTitle = 'Search adjusted'; $alertMessage = 'Enter at least two characters and use a valid page number.'; require __DIR__ . '/../../Components/Admin/alert.php'; ?>
<?php elseif ($customerList['capped']): ?>
  <?php $alertTone = 'info'; $alertTitle = 'Refine this search'; $alertMessage = 'Only the first 100 matching accounts are available. Add more of the name, email, or phone number.'; require __DIR__ . '/../../Components/Admin/alert.php'; ?>
<?php endif; ?>

<section class="admin-filter-panel admin-customer-search" aria-labelledby="customer-search-title">
  <div class="admin-filter-panel__heading">
    <h2 id="customer-search-title">Find a customer</h2>
    <?php if (!$customerList['searchRequired']): ?><a href="<?php echo BASE_URL . 'admin/customers/index.php'; ?>">Clear search</a><?php endif; ?>
  </div>
  <!-- FORM PURPOSE: Submit filter form with GET; the receiving PHP route must validate the values before using them. -->
  <form class="admin-filter-form" action="<?php echo BASE_URL . 'admin/customers/index.php'; ?>" method="get">
    <div class="admin-field admin-field--wide">
      <label for="customer-search">Name, email, or phone</label>
      <input id="customer-search" name="q" type="search" minlength="2" maxlength="100" required
             autocomplete="off" value="<?php echo $escape($customerList['search']); ?>"
             placeholder="Enter at least two characters">
      <small>Results are limited and contact details remain masked until you open a record.</small>
    </div>
    <div class="admin-filter-form__actions">
      <button class="admin-button" type="submit">Search customers</button>
    </div>
  </form>
</section>

<section class="admin-panel admin-panel--table admin-order-results" aria-labelledby="customer-results-title">
  <header class="admin-panel__header">
    <div><h2 id="customer-results-title">Customer accounts</h2></div>
    <?php if (!$customerList['searchRequired'] && (int) $customerList['total'] > 0): ?>
      <span class="admin-panel__meta">Page <?php echo $escape($customerList['page']); ?> of <?php echo $escape($customerList['totalPages']); ?></span>
    <?php endif; ?>
  </header>

  <?php if ($customerList['customers'] === []): ?>
    <?php
      if (is_string($customerList['loadError'])) {
          $emptyTitle = 'Customers unavailable';
          $emptyMessage = 'No account information could be displayed safely.';
      } elseif ($customerList['searchRequired']) {
          $emptyTitle = 'Search required';
          $emptyMessage = 'Enter part of a customer name, email address, or phone number. The directory is not exposed as an unrestricted list.';
      } else {
          $emptyTitle = 'No matching customers';
          $emptyMessage = 'Check the spelling or try another known customer detail.';
      }
      require __DIR__ . '/../../Components/Admin/empty-state.php';
    ?>
  <?php else: ?>
    <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable customer results table">
      <table class="admin-table admin-customers-table">
        <caption class="admin-visually-hidden">Customer search results with masked contact hints</caption>
        <thead><tr><th scope="col">Customer</th><th scope="col">Email hint</th><th scope="col">Phone hint</th><th scope="col">Account</th><th scope="col">Joined</th><th scope="col">Last order</th></tr></thead>
        <tbody>
          <?php foreach ($customerList['customers'] as $customer): ?>
            <?php // URL IDIOM: http_build_query safely encodes each query-string value. ?>
            <?php $detailUrl = BASE_URL . 'admin/customers/detail.php?' . http_build_query(['id' => $customer['id'], 'return' => http_build_query($customerList['queryState'])]); ?>
            <tr>
              <td><a class="admin-table__primary-link" href="<?php echo $escape($detailUrl); ?>"><?php echo $escape($customer['customer_name']); ?></a></td>
              <td><?php echo $escape($customer['email_hint']); ?></td>
              <td><?php echo $escape($customer['phone_hint']); ?></td>
              <td><?php $status = $customer['account_status']; require __DIR__ . '/../../Components/Admin/status-badge.php'; ?></td>
              <td><time datetime="<?php echo $escape($customer['created_at']); ?>"><?php echo $escape($formatDate($customer['created_at'])); ?></time></td>
              <td><?php echo $escape($formatDate($customer['last_order_at'])); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <footer class="admin-order-results__footer">
      <span>Showing <?php echo $escape((($customerList['page'] - 1) * $customerList['perPage']) + 1); ?>–<?php echo $escape(min($customerList['page'] * $customerList['perPage'], $customerList['total'])); ?> of <?php echo $escape($customerList['total']); ?></span>
      <?php
        $currentPage = $customerList['page'];
        $totalPages = $customerList['totalPages'];
        $paginationBaseUrl = BASE_URL . 'admin/customers/index.php';
        $paginationQuery = $customerList['queryState'];
        require __DIR__ . '/../../Components/Admin/pagination.php';
      ?>
    </footer>
  <?php endif; ?>
</section>
