<?php declare(strict_types=1);

use App\View\AdminIcons;

?>
<header class="admin-page-header"><div><h1>Order Trash</h1><p>Deleted orders only. Restore an order to return it to the active lists.</p></div><a class="admin-button admin-button--secondary" href="<?php echo BASE_URL . 'admin/orders/index.php'; ?>"><?php echo AdminIcons::render('back'); ?>Back to orders</a></header>
<?php if ($trashNotice['success'] !== null || $trashNotice['errors'] !== []): ?>
  <?php $alertTone = $trashNotice['errors'] === [] ? 'success' : 'error';
    $alertTitle = 'Order Trash';
    $alertMessage = $trashNotice['success'] ?? implode(' ', $trashNotice['errors']);
    require VIEW_PATH . '/Components/Admin/alert.php'; ?>
<?php endif; ?>
<section class="admin-panel admin-panel--table" aria-labelledby="trashed-orders-title">
  <header class="admin-panel__header"><h2 id="trashed-orders-title">Deleted orders</h2><span class="admin-panel__meta"><?php echo $escape($trash['total']); ?> orders</span></header>
  <?php if ($trash['orders'] === []): ?>
    <?php $emptyTitle = $trashNotice['errors'] === [] ? 'Trash is empty' : 'Trash unavailable';
      $emptyMessage = $trashNotice['errors'] === [] ? 'Deleted orders will appear here.' : 'Please refresh or try again later.';
      require VIEW_PATH . '/Components/Admin/empty-state.php'; ?>
  <?php else: ?>
    <div class="admin-table-scroll" tabindex="0" role="region" aria-label="Scrollable deleted orders table"><table class="admin-table"><caption class="admin-visually-hidden">Deleted orders available to restore</caption><thead><tr><th scope="col">Order</th><th scope="col">Customer</th><th scope="col">Order status</th><th scope="col">Deleted</th><th scope="col">Actions</th></tr></thead><tbody>
      <?php foreach ($trash['orders'] as $trashedOrder): ?>
        <tr><td><?php echo $escape($trashedOrder['order_number']); ?></td><td><?php echo $escape($trashedOrder['customer_name_snapshot']); ?></td><td><?php $status = $trashedOrder['order_status'];
          require VIEW_PATH . '/Components/Admin/status-badge.php'; ?></td><td><?php echo $escape($trashedOrder['deleted_at']); ?></td><td>
          <form action="<?php echo BASE_URL . 'admin/orders/trash.php'; ?>" method="post"><input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>"><input type="hidden" name="id" value="<?php echo $escape($trashedOrder['id']); ?>"><button class="admin-button admin-button--secondary" type="submit" aria-label="<?php echo $escape('Restore order ' . $trashedOrder['order_number']); ?>">Restore</button></form>
        </td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php $currentPage = $trash['page'];
      $totalPages = $trash['pages'];
      $paginationBaseUrl = BASE_URL . 'admin/orders/trash.php';
      $paginationQuery = [];
      require VIEW_PATH . '/Components/Admin/pagination.php'; ?>
  <?php endif; ?>
</section>
