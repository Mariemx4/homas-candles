<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Reusable view fragment for Pagination.
 *
 * DATA FLOW: A parent view prepares the variables this fragment expects and requires it in the same PHP scope. This is a partial, not a page that should be opened directly.
 */

if ($totalPages <= 1) {
    return;
}

$paginationPageParameter ??= 'page';
$paginationUrl = static function (int $targetPage) use ($paginationBaseUrl, $paginationQuery, $paginationPageParameter): string {
    $query = $paginationQuery;
    $query[$paginationPageParameter] = $targetPage;
    // URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
    return $paginationBaseUrl . '?' . http_build_query($query);
};
$firstVisiblePage = max(1, $currentPage - 2);
$lastVisiblePage = min($totalPages, $currentPage + 2);
?>
<nav class="admin-pagination" aria-label="<?php echo $escape($paginationLabel ?? 'Result pages'); ?>">
  <?php if ($currentPage > 1): ?>
    <a class="admin-pagination__direction" href="<?php echo $escape($paginationUrl($currentPage - 1)); ?>" rel="prev">Previous</a>
  <?php else: ?>
    <span class="admin-pagination__direction" aria-disabled="true">Previous</span>
  <?php endif; ?>

  <span class="admin-pagination__pages">
    <?php if ($firstVisiblePage > 1): ?>
      <a href="<?php echo $escape($paginationUrl(1)); ?>">1</a>
      <?php if ($firstVisiblePage > 2): ?><span aria-hidden="true">…</span><?php endif; ?>
    <?php endif; ?>
    <?php for ($pageNumber = $firstVisiblePage; $pageNumber <= $lastVisiblePage; $pageNumber++): ?>
      <?php if ($pageNumber === $currentPage): ?>
        <span class="admin-pagination__current" aria-current="page"><span class="admin-visually-hidden">Page </span><?php echo $pageNumber; ?></span>
      <?php else: ?>
        <a href="<?php echo $escape($paginationUrl($pageNumber)); ?>"><span class="admin-visually-hidden">Page </span><?php echo $pageNumber; ?></a>
      <?php endif; ?>
    <?php endfor; ?>
    <?php if ($lastVisiblePage < $totalPages): ?>
      <?php if ($lastVisiblePage < $totalPages - 1): ?><span aria-hidden="true">…</span><?php endif; ?>
      <a href="<?php echo $escape($paginationUrl($totalPages)); ?>"><?php echo $totalPages; ?></a>
    <?php endif; ?>
  </span>

  <?php if ($currentPage < $totalPages): ?>
    <a class="admin-pagination__direction" href="<?php echo $escape($paginationUrl($currentPage + 1)); ?>" rel="next">Next</a>
  <?php else: ?>
    <span class="admin-pagination__direction" aria-disabled="true">Next</span>
  <?php endif; ?>
</nav>
