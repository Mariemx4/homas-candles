<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Reusable view fragment for Catalogue Tabs.
 *
 * DATA FLOW: A parent view prepares the variables this fragment expects and requires it in the same PHP scope. This is a partial, not a page that should be opened directly.
 */

$catalogueTabs = [
    ['key' => 'products', 'label' => 'Products', 'href' => BASE_URL . 'admin/catalogue/products.php'],
    ['key' => 'categories', 'label' => 'Categories', 'href' => BASE_URL . 'admin/catalogue/categories.php'],
    ['key' => 'product_types', 'label' => 'Product Types', 'href' => BASE_URL . 'admin/catalogue/product-types.php'],
    ['key' => 'colors', 'label' => 'Colors', 'href' => BASE_URL . 'admin/catalogue/colors.php'],
    ['key' => 'promotions', 'label' => 'Promotions', 'href' => BASE_URL . 'admin/catalogue/promotions.php'],
    ['key' => 'scents', 'label' => 'Scents', 'href' => BASE_URL . 'admin/catalogue/scents.php'],
];
$activeCatalogueTab ??= 'products';
?>
<nav class="admin-catalogue-tabs" aria-label="Catalogue sections">
  <?php foreach ($catalogueTabs as $tab): ?>
    <a class="admin-catalogue-tabs__item<?php echo $tab['key'] === $activeCatalogueTab ? ' admin-catalogue-tabs__item--current' : ''; ?>"
       href="<?php echo $escape($tab['href']); ?>"
       <?php echo $tab['key'] === $activeCatalogueTab ? ' aria-current="page"' : ''; ?>>
      <?php echo $escape($tab['label']); ?>
    </a>
  <?php endforeach; ?>
</nav>
