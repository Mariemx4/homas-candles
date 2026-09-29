<?php declare(strict_types=1); ?>
<header class="admin-page-header"><div><h1>Order Trash</h1><p>Restore an order to return it to the active lists.</p></div><a class="admin-button admin-button--secondary" href="<?php echo BASE_URL . 'admin/orders/index.php'; ?>">Back to orders</a></header>
<?php if ($trashNotice['success'] !== null || $trashNotice['errors'] !== []): ?>
  <?php $alertTone = $trashNotice['errors'] === [] ? 'success' : 'error'; $alertTitle = 'Order Trash'; $alertMessage = $trashNotice['success'] ?? implode(' ', $trashNotice['errors']); require __DIR__ . '/../../Components/Admin/alert.php'; ?>
<?php endif; ?>
<section class="admin-panel">
  <?php if ($trash['orders'] === []): ?>
    <?php $emptyTitle = 'Trash is empty'; $emptyMessage = 'Deleted orders will appear here.'; require __DIR__ . '/../../Components/Admin/empty-state.php'; ?>
  <?php else: ?>
    <div class="admin-table-scroll"><table class="admin-table"><thead><tr><th>Order</th><th>Customer</th><th>Deleted</th><th>Actions</th></tr></thead><tbody>
      <?php foreach ($trash['orders'] as $trashedOrder): ?>
        <tr><td><?php echo $escape($trashedOrder['order_number']); ?></td><td><?php echo $escape($trashedOrder['customer_name_snapshot']); ?></td><td><?php echo $escape($trashedOrder['deleted_at']); ?></td><td>
          <form action="<?php echo BASE_URL . 'admin/orders/trash.php'; ?>" method="post"><input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>"><input type="hidden" name="id" value="<?php echo $escape($trashedOrder['id']); ?>"><button class="admin-button admin-button--secondary" type="submit">Restore</button></form>
        </td></tr>
      <?php endforeach; ?>
    </tbody></table></div>
    <?php $currentPage = $trash['page']; $totalPages = $trash['pages']; $paginationBaseUrl = BASE_URL . 'admin/orders/trash.php'; $paginationQuery = []; require __DIR__ . '/../../Components/Admin/pagination.php'; ?>
  <?php endif; ?>
</section>
