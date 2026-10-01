<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Reusable view fragment for Catalogue Results.
 *
 * DATA FLOW: A parent view prepares the variables this fragment expects and requires it in the same PHP scope. This is a partial, not a page that should be opened directly.
 */

if (!isset($catalogueView, $products) || !is_array($catalogueView) || !is_array($products)) {
    throw new RuntimeException('Catalogue result view data is required.');
}

$catalogueError ??= null;
$classPrefix = $catalogueView['class_prefix'];
?>

<div class="ui-state-group ui-state-group--compact" data-catalogue-states
  data-initial-state="<?php echo $catalogueError !== null ? 'error' : ($products !== [] ? 'success' : ''); ?>"
  <?php echo $catalogueError === null && $products === [] ? 'hidden' : ''; ?>>
  <div class="ui-state" data-ui-state="loading" hidden>
    <span class="ui-state__spinner" aria-hidden="true"></span>
    <div><h3><?php echo htmlspecialchars($catalogueView['loading_title']); ?></h3><p><?php echo site_text('catalogue.applying'); ?></p></div>
  </div>
  <div class="ui-state ui-state--error" data-ui-state="error" role="alert"
    <?php echo $catalogueError === null ? 'hidden' : ''; ?>>
    <i data-lucide="x" aria-hidden="true"></i>
    <div><h3><?php echo htmlspecialchars($catalogueView['error_title']); ?></h3><p data-ui-state-message><?php echo htmlspecialchars((string) $catalogueError); ?></p></div>
  </div>
  <div class="ui-state ui-state--success" data-ui-state="success"
    <?php echo $products === [] ? 'hidden' : ''; ?>>
    <i data-lucide="check" aria-hidden="true"></i>
    <div><h3><?php echo site_text('catalogue.ready'); ?></h3><p><?php echo $totalProducts; ?> <?php echo site_text('catalogue.match'); ?></p></div>
  </div>
</div>

<div class="<?php echo htmlspecialchars($classPrefix); ?>-product-grid" data-product-grid data-catalogue-results
  data-catalogue-state="<?php echo $products === [] ? 'empty' : 'success'; ?>">
  <?php foreach ($products as $productCard): ?>
    <?php require __DIR__ . '/product-card.php'; ?>
  <?php endforeach; ?>
</div>

<?php if ($products === [] && $catalogueError === null): ?>
  <div class="<?php echo htmlspecialchars($classPrefix); ?>-empty-state">
    <div class="<?php echo htmlspecialchars($classPrefix); ?>-empty-state__icon" aria-hidden="true">
      <i data-lucide="<?php echo htmlspecialchars($catalogueView['empty_icon']); ?>"></i>
    </div>
    <p class="<?php echo htmlspecialchars($classPrefix); ?>-eyebrow"><?php echo htmlspecialchars($catalogueView['empty_eyebrow']); ?></p>
    <h2><?php echo htmlspecialchars($catalogueView['empty_title']); ?></h2>
    <p><?php echo htmlspecialchars($catalogueView['empty_description']); ?></p>
    <a href="<?php echo BASE_URL . $catalogueView['empty_link']; ?>"><?php echo htmlspecialchars($catalogueView['empty_link_label']); ?></a>
  </div>
<?php endif; ?>

<?php if ($totalPages > 1): ?>
  <?php
  $pageQuery = ['sort' => $selectedSort, 'lang' => \App\Services\Locale::current()];
  if (isset($selectedCategory) && $selectedCategory !== 'all') {
      $pageQuery['category'] = $selectedCategory;
  }
  if ($selectedProductType !== 'all') {
      $pageQuery['type'] = $selectedProductType;
  }
  if ($selectedSearch !== '') {
      $pageQuery['query'] = $selectedSearch;
  }
  $pageLink = static function (int $page) use ($cataloguePagePath, $pageQuery): string {
      return BASE_URL . $cataloguePagePath . (str_contains($cataloguePagePath, '?') ? '&' : '?')
          . http_build_query($pageQuery + ['page' => $page]);
  };
  ?>
  <nav class="catalogue-pagination" aria-label="<?php echo site_text('catalogue.pages'); ?>">
    <?php if ($currentPage > 1): ?>
      <a href="<?php echo htmlspecialchars($pageLink($currentPage - 1)); ?>"><?php echo site_text('catalogue.previous'); ?></a>
    <?php endif; ?>
    <span><?php echo site_text('catalogue.page'); ?> <?php echo $currentPage; ?> <?php echo site_text('catalogue.of'); ?> <?php echo $totalPages; ?></span>
    <?php if ($currentPage < $totalPages): ?>
      <a href="<?php echo htmlspecialchars($pageLink($currentPage + 1)); ?>"><?php echo site_text('catalogue.next'); ?></a>
    <?php endif; ?>
  </nav>
<?php endif; ?>
