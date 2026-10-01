<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Reusable view fragment for Customer Request History.
 *
 * DATA FLOW: A parent view prepares the variables this fragment expects and requires it in the same PHP scope. This is a partial, not a page that should be opened directly.
 */

$requestPagination = $customerDetail['requestPagination'];
?>
<section class="admin-panel admin-panel--table admin-customer-history" aria-labelledby="customer-requests-title">
  <header class="admin-panel__header"><div><h2 id="customer-requests-title">Custom-request history</h2></div><span class="admin-panel__meta"><?php echo $escape($requestPagination['total']); ?> total</span></header>
  <p class="admin-customer-history__note">Request names and preferences are submission-time snapshots. Product links may be absent while the historical request remains valid.</p>
  <?php if ($customerDetail['requests'] === []): ?>
    <?php $emptyTitle = 'No linked custom requests'; $emptyMessage = 'Custom enquiries linked to this account will appear here.'; require __DIR__ . '/empty-state.php'; ?>
  <?php else: ?>
    <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable customer custom-request history"><table class="admin-table admin-customer-requests-table"><caption class="admin-visually-hidden">Custom requests linked to this customer account</caption><thead><tr><th scope="col">Request</th><th scope="col">Request snapshot</th><th scope="col">Category or product</th><th scope="col">Required</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead><tbody>
      <?php foreach ($customerDetail['requests'] as $request): ?>
        <tr>
          <?php // URL IDIOM: http_build_query safely encodes each query-string value. ?>
          <td><a class="admin-table__primary-link" href="<?php echo $escape(BASE_URL . 'admin/requests/detail.php?' . http_build_query(['id' => $request['id']])); ?>"><?php echo $escape($request['request_number']); ?></a><small><?php echo $escape($formatDateTime($request['created_at'])); ?></small></td>
          <td><?php echo $escape($request['customer_name_snapshot']); ?><small>Preferred: <?php echo $escape($humanize($request['preferred_contact_method'])); ?></small></td>
          <td><?php echo $escape($request['product_name'] ?? $request['category_name']); ?><?php if ($request['product_name'] === null): ?><small>Category snapshot context</small><?php endif; ?></td>
          <td><?php echo $escape($request['required_date'] ?? 'Not specified'); ?></td>
          <td><?php $status = $request['request_status']; require __DIR__ . '/status-badge.php'; ?></td>
          <td><?php if ((string) $request['request_status'] === 'AGREED' && $request['converted_order_id'] === null): ?><a class="admin-button admin-button--secondary" href="<?php echo $escape(BASE_URL . 'admin/requests/convert.php?id=' . $request['id']); ?>">Create order</a><?php else: ?><span class="admin-muted">View request</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <footer class="admin-order-results__footer"><span>Page <?php echo $escape($requestPagination['page']); ?> of <?php echo $escape($requestPagination['totalPages']); ?></span><?php $currentPage = $requestPagination['page']; $totalPages = $requestPagination['totalPages']; $paginationBaseUrl = BASE_URL . 'admin/customers/detail.php'; $paginationQuery = ['id' => $customer['id'], 'orders_page' => $customerDetail['orderPagination']['page'], 'requests_page' => $requestPagination['page']]; $paginationLabel = 'Customer custom-request history pages'; $paginationPageParameter = 'requests_page'; require __DIR__ . '/pagination.php'; ?></footer>
  <?php endif; ?>
</section>
