<?php

declare(strict_types=1);



$topLabels = ['products' => 'Products', 'scents' => 'Scents', 'colors' => 'Colors'];
?>
<section class="admin-report-top" aria-labelledby="report-top-title">
  <header class="admin-section-heading">
    <div>
      <p class="admin-eyebrow">Completed orders</p>
      <h2 id="report-top-title">Top selections</h2>
    </div><span>Ranked by snapshot quantity</span>
  </header>
  <div class="admin-report-top-grid">
    <?php foreach ($topLabels as $key => $label): ?>
      <article class="admin-panel">
        <header class="admin-panel__header">
          <h3><?php echo $escape($label); ?></h3>
        </header>
        <?php if ($reportResult['top'][$key] === []): ?><p class="admin-report-empty">No completed-order selections in range.</p><?php else: ?><ol class="admin-report-ranking"><?php foreach ($reportResult['top'][$key] as $row): ?><li><span><?php echo $escape($row['item_name']); ?></span><strong><?php echo $escape($row['quantity']); ?></strong></li><?php endforeach; ?></ol><?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
</section>