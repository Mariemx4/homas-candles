<?php

declare(strict_types=1);



$orderPagination = $customerDetail['orderPagination'];
?>
<section class="admin-panel admin-panel--table admin-customer-history" aria-labelledby="customer-orders-title">
  <header class="admin-panel__header"><div><h2 id="customer-orders-title">Order history</h2></div><span class="admin-panel__meta"><?php echo $escape($orderPagination['total']); ?> total</span></header>
  <p class="admin-customer-history__note">Names and contact values below are frozen order snapshots. They may differ from the current account profile above.</p>
  <?php if ($customerDetail['orders'] === []): ?>
    <?php $emptyTitle = 'No linked orders';
      $emptyMessage = 'Orders linked to this registered account will appear here.';
      require VIEW_PATH . '/Components/Admin/empty-state.php'; ?>
  <?php else: ?>
    <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable customer order history"><table class="admin-table admin-customer-orders-table"><caption class="admin-visually-hidden">Orders linked to this customer account</caption><thead><tr><th scope="col">Order</th><th scope="col">Order snapshot</th><th scope="col">Items</th><th scope="col">Required</th><th scope="col">Total</th><th scope="col">Status</th></tr></thead><tbody>
      <?php foreach ($customerDetail['orders'] as $order): ?>
        <tr>
          <?php // URL IDIOM: http_build_query safely encodes each query-string value.?>
          <td><a class="admin-table__primary-link" href="<?php echo $escape(BASE_URL . 'admin/orders/detail.php?' . http_build_query(['id' => $order['id']])); ?>"><?php echo $escape($order['order_number']); ?></a><small><?php echo $escape($humanize($order['order_source'])); ?></small></td>
          <td><strong><?php echo $escape($order['customer_name_snapshot']); ?></strong><small><?php echo $escape($order['customer_email_snapshot'] ?? 'No email snapshot'); ?> · <?php echo $escape($order['customer_phone_snapshot']); ?></small></td>
          <td><?php echo $escape($order['item_count']); ?><?php if ((int) $order['snapshot_only_item_count'] > 0): ?><small><?php echo $escape($order['snapshot_only_item_count']); ?> retained by snapshot</small><?php endif; ?></td>
          <td><?php echo $escape($order['required_date']); ?></td>
          <td><?php echo $escape($formatMoney($order['total_amount'])); ?></td>
          <td><?php $status = $order['order_status'];
          require VIEW_PATH . '/Components/Admin/status-badge.php'; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <footer class="admin-order-results__footer"><span>Page <?php echo $escape($orderPagination['page']); ?> of <?php echo $escape($orderPagination['totalPages']); ?></span><?php $currentPage = $orderPagination['page'];
$totalPages = $orderPagination['totalPages'];
$paginationBaseUrl = BASE_URL . 'admin/customers/detail.php';
$paginationQuery = ['id' => $customer['id'], 'orders_page' => $orderPagination['page'], 'requests_page' => $customerDetail['requestPagination']['page']];
$paginationLabel = 'Customer order history pages';
$paginationPageParameter = 'orders_page';
require VIEW_PATH . '/Components/Admin/pagination.php'; ?></footer>
  <?php endif; ?>
</section>
