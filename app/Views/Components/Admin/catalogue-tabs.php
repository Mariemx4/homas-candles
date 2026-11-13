<?php

declare(strict_types=1);

use App\View\AdminNavigation;
use App\View\AdminIcons;

$catalogueTabs = AdminNavigation::catalogueTabs();
$activeCatalogueTab ??= 'products';
?>
<nav class="admin-catalogue-tabs" aria-label="Catalogue sections">
  <?php foreach ($catalogueTabs as $tab): ?>
    <a class="admin-catalogue-tabs__item<?php echo $tab['key'] === $activeCatalogueTab ? ' admin-catalogue-tabs__item--current' : ''; ?>"
      href="<?php echo $escape($tab['href']); ?>"
      <?php echo $tab['key'] === $activeCatalogueTab ? ' aria-current="page"' : ''; ?>>
      <?php echo AdminIcons::render($tab['icon']); ?>
      <?php echo $escape($tab['label']); ?>
    </a>
  <?php endforeach; ?>
</nav>
