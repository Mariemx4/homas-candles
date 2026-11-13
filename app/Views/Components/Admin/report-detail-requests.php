<?php

declare(strict_types=1);

?>
<table class="admin-table admin-report-table">
  <caption class="admin-visually-hidden">Custom-request supporting records</caption>
  <thead>
    <tr>
      <th scope="col">Request</th>
      <th scope="col">Created</th>
      <th scope="col">Status</th>
      <th scope="col">Converted</th>
    </tr>
  </thead>
  <tbody><?php foreach ($reportResult['details'] as $row): ?><tr>
        <td><a href="<?php echo $escape(BASE_URL . 'admin/requests/detail.php?id=' . $row['id']); ?>"><?php echo $escape($row['request_number']); ?></a></td>
        <td><?php echo $escape($formatDateTime($row['created_at'])); ?></td>
        <td><?php $status = $row['request_status'];
      require VIEW_PATH . '/Components/Admin/status-badge.php'; ?></td>
        <td><?php echo $escape($formatDateTime($row['converted_at'])); ?></td>
      </tr><?php endforeach; ?></tbody>
</table>