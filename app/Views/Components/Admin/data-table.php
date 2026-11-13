<?php

declare(strict_types=1);

use App\View\Presentation;

$formatDate = Presentation::date(...);
$humanize = Presentation::humanize(...);
?>
<?php if ($tableRows === []): ?>
  <?php require VIEW_PATH . '/Components/Admin/empty-state.php'; ?>
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
              <td<?php echo ($column['type'] ?? 'text') === 'money' ? ' class="admin-table__money"' : ''; ?> data-label="<?php echo $escape($column['label']); ?>">
                <?php $cellValue = $row[$column['key']] ?? null; ?>
                <?php if (($column['type'] ?? 'text') === 'link'): ?>
                  <?php $cellUrl = BASE_URL . $column['route'] . '?' . http_build_query([$column['parameter'] ?? 'id' => $row[$column['idKey'] ?? 'id']]); ?>
                  <a class="admin-table__primary-link" href="<?php echo $escape($cellUrl); ?>"><?php echo $escape($cellValue); ?></a>
                <?php elseif (($column['type'] ?? 'text') === 'status'): ?>
                  <?php $status = $cellValue;
                    require VIEW_PATH . '/Components/Admin/status-badge.php'; ?>
                <?php elseif (($column['type'] ?? 'text') === 'date'): ?>
                  <time datetime="<?php echo $escape(is_string($cellValue) ? substr($cellValue, 0, 10) : ''); ?>"><?php echo $escape($formatDate($cellValue)); ?></time>
                  <?php if (isset($column['contextKey'], $row[$column['contextKey']])): ?>
                    <?php $deadline = $row[$column['contextKey']]; ?>
                    <span class="admin-badge admin-badge--<?php echo $escape($deadline['tone']); ?>"><?php echo $escape($deadline['label']); ?></span>
                  <?php endif; ?>
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
