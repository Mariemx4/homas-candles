<?php declare(strict_types=1);

/**
 * FILE PURPOSE: Reusable view fragment for Report Detail Materials.
 *
 * DATA FLOW: A parent view prepares the variables this fragment expects and requires it in the same PHP scope. This is a partial, not a page that should be opened directly.
 */ ?>
<table class="admin-table admin-report-table">
  <caption class="admin-visually-hidden">Material-use and waste supporting records</caption>
  <thead><tr><th scope="col">Material</th><th scope="col">Recorded</th><th scope="col">Type</th><th scope="col">Quantity</th><th scope="col">Snapshot value</th></tr></thead>
  <tbody><?php foreach ($reportResult['details'] as $row): ?><tr>
    <td>
      <?php // The materials route switches to edit mode when it receives a valid id. ?>
      <a href="<?php echo $escape(BASE_URL . 'admin/inventory/materials.php?' . http_build_query(['id' => $row['material_id']])); ?>"><?php echo $escape($row['name']); ?></a>
      <small><?php echo $escape($row['sku']); ?></small>
    </td>
    <td><?php echo $escape($formatDateTime($row['created_at'])); ?></td>
    <td><?php echo $escape($humanize($row['movement_type'])); ?></td>
    <td><?php echo $escape($number($row['quantity'])); ?> <?php echo $escape($row['unit']); ?></td>
    <td><?php echo $escape($money($row['value_amount'])); ?></td>
  </tr><?php endforeach; ?></tbody>
</table>
