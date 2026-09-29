<?php declare(strict_types=1);

/**
 * FILE PURPOSE: Reusable view fragment for Report Detail Payments.
 *
 * DATA FLOW: A parent view prepares the variables this fragment expects and requires it in the same PHP scope. This is a partial, not a page that should be opened directly.
 */ ?>
<table class="admin-table admin-report-table">
  <caption class="admin-visually-hidden">Verified-payment supporting records</caption>
  <thead><tr><th scope="col">Order</th><th scope="col">Verified</th><th scope="col">Method</th><th scope="col">Type</th><th scope="col">Amount</th></tr></thead>
  <tbody><?php foreach ($reportResult['details'] as $row): ?><tr>
    <td><a href="<?php echo $escape(BASE_URL . 'admin/orders/detail.php?id=' . $row['order_id']); ?>"><?php echo $escape($row['order_number']); ?></a></td>
    <td><?php echo $escape($formatDateTime($row['verified_at'])); ?></td>
    <td><?php echo $escape($humanize($row['payment_method'])); ?></td>
    <td><?php echo $escape($humanize($row['payment_type'])); ?></td>
    <td><?php echo $escape($money($row['amount'])); ?></td>
  </tr><?php endforeach; ?></tbody>
</table>
