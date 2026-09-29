<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Reusable view fragment for Data Table.
 *
 * DATA FLOW: A parent view prepares the variables this fragment expects and requires it in the same PHP scope. This is a partial, not a page that should be opened directly.
 */

$formatDate = static function (mixed $value): string {
    if (!is_string($value) || $value === '') {
        return 'Not set';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
    return $date instanceof DateTimeImmutable ? $date->format('d M Y') : 'Not set';
};
$humanize = static fn (mixed $value): string => ucwords(strtolower(str_replace('_', ' ', (string) $value)));
?>
<?php if ($tableRows === []): ?>
  <?php require __DIR__ . '/empty-state.php'; ?>
<?php else: ?>
  <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable <?php echo $escape($tableCaption); ?> table">
    <table class="admin-table">
      <caption class="admin-visually-hidden"><?php echo $escape($tableCaption); ?></caption>
      <thead>
        <tr>
          <?php foreach ($tableColumns as $column): ?>
            <th scope="col"><?php echo $escape($column['label']); ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($tableRows as $row): ?>
          <tr>
            <?php foreach ($tableColumns as $column): ?>
              <td data-label="<?php echo $escape($column['label']); ?>">
                <?php $cellValue = $row[$column['key']] ?? null; ?>
                <?php if (($column['type'] ?? 'text') === 'status'): ?>
                  <?php $status = $cellValue; require __DIR__ . '/status-badge.php'; ?>
                <?php elseif (($column['type'] ?? 'text') === 'date'): ?>
                  <time datetime="<?php echo $escape(is_string($cellValue) ? substr($cellValue, 0, 10) : ''); ?>"><?php echo $escape($formatDate($cellValue)); ?></time>
                <?php elseif (($column['type'] ?? 'text') === 'money'): ?>
                  <?php echo $escape(number_format((float) $cellValue, 3) . ' ' . (string) ($row[$column['currencyKey']] ?? 'TND')); ?>
                <?php elseif (($column['type'] ?? 'text') === 'humanized'): ?>
                  <?php echo $escape($humanize($cellValue)); ?>
                <?php else: ?>
                  <?php echo $escape($cellValue); ?>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <footer class="admin-list-footer" aria-live="polite">
    Showing <?php echo $escape(count($tableRows)); ?> <?php echo count($tableRows) === 1 ? 'record' : 'records'; ?>
  </footer>
<?php endif; ?>
