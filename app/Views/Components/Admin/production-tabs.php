<?php

declare(strict_types=1);



$productionTabs = [
    ['key' => 'queue', 'label' => 'Queue', 'href' => BASE_URL . 'admin/production/queue.php'],
    ['key' => 'calendar', 'label' => 'Calendar', 'href' => BASE_URL . 'admin/production/calendar.php'],
    ['key' => 'sheet', 'label' => 'Preparation sheet', 'href' => BASE_URL . 'admin/production/sheet.php'],
];
$activeProductionTab ??= 'queue';
?>
<nav class="admin-production-tabs" aria-label="Production sections">
  <?php foreach ($productionTabs as $tab): ?>
    <a class="admin-production-tabs__item<?php echo $tab['key'] === $activeProductionTab ? ' admin-production-tabs__item--current' : ''; ?>"
       href="<?php echo $escape($tab['href']); ?>"
       <?php echo $tab['key'] === $activeProductionTab ? ' aria-current="page"' : ''; ?>>
      <?php echo $escape($tab['label']); ?>
    </a>
  <?php endforeach; ?>
</nav>
