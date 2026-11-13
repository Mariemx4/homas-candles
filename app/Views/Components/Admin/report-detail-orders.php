<?php declare(strict_types=1);

use App\View\AdminIcons;

?>
<table class="admin-table admin-report-table">
  <caption class="admin-visually-hidden">Order supporting records</caption>
  <thead><tr><th scope="col">Order</th><th scope="col">Created</th><th scope="col">Status</th><th scope="col">Visibility</th><th scope="col">Total</th><th scope="col">Known cost</th><th scope="col">Cost coverage</th></tr></thead>
  <tbody><?php foreach ($reportResult['details'] as $row): ?><tr>
    <td><?php if ($row['deleted_at'] === null): ?><a href="<?php echo $escape(BASE_URL . 'admin/orders/detail.php?id=' . $row['id']); ?>"><?php echo $escape($row['order_number']); ?></a><?php else: ?><?php echo $escape($row['order_number']); ?><?php endif; ?><small><?php echo $escape($humanize($row['order_source'])); ?></small></td>
    <td><?php echo $escape($formatDateTime($row['created_at'])); ?></td>
    <td><?php $status = $row['order_status'];
      require VIEW_PATH . '/Components/Admin/status-badge.php'; ?></td>
    <td><?php if ($row['deleted_at'] === null): ?>Active<?php else: ?>In Trash since <?php echo $escape($formatDateTime($row['deleted_at'])); ?> <a href="<?php echo $escape(BASE_URL . 'admin/orders/trash.php'); ?>"><?php echo AdminIcons::render('eye'); ?>View Trash</a><?php endif; ?></td>
    <td><?php echo $escape($money($row['total_amount'])); ?></td>
    <td><?php echo $escape($money($row['known_cost'])); ?></td>
    <td><?php echo (int) $row['missing_cost_items'] === 0 ? 'Complete' : $escape($row['missing_cost_items']) . ' missing'; ?></td>
  </tr><?php endforeach; ?></tbody>
</table>
