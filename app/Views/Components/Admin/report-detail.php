<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Reusable view fragment for Report Detail.
 *
 * DATA FLOW: A parent view prepares the variables this fragment expects and requires it in the same PHP scope. This is a partial, not a page that should be opened directly.
 */

$pagination = $reportResult['pagination'];
$section = $filters['section'];
// URL IDIOM: Encode an associative array into a valid query string instead of concatenating raw parameter text.
$exportUrl = BASE_URL . 'admin/reports/export.php?' . http_build_query([
    'start' => $filters['start'],
    'end' => $filters['end'],
    'section' => $section,
]);
$detailComponents = [
    'orders' => __DIR__ . '/report-detail-orders.php',
    'payments' => __DIR__ . '/report-detail-payments.php',
    'requests' => __DIR__ . '/report-detail-requests.php',
    'materials' => __DIR__ . '/report-detail-materials.php',
];
?>
<section class="admin-panel admin-panel--table admin-report-detail" aria-labelledby="report-detail-title">
  <header class="admin-panel__header">
    <div><h2 id="report-detail-title"><?php echo $escape($sectionLabels[$section]); ?></h2></div>
    <a class="admin-button admin-button--secondary" href="<?php echo $escape($exportUrl); ?>">Export CSV</a>
  </header>
  <p class="admin-report-detail__note">CSV uses these same dates and dataset, including retained orders in Trash. It is limited to 5,000 rows and excludes customer contact, notes, payment evidence, and authentication data.</p>
  <?php if ($reportResult['details'] === []): ?>
    <?php $emptyTitle = 'No supporting records'; $emptyMessage = 'No records match this date range and report definition.'; require __DIR__ . '/empty-state.php'; ?>
  <?php else: ?>
    <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable report detail table">
      <?php require $detailComponents[$section]; ?>
    </div>
    <footer class="admin-order-results__footer">
      <span>Showing <?php echo $escape((($pagination['page'] - 1) * $pagination['perPage']) + 1); ?>–<?php echo $escape(min($pagination['page'] * $pagination['perPage'], $pagination['total'])); ?> of <?php echo $escape($pagination['total']); ?></span>
      <?php
        $currentPage = $pagination['page'];
        $totalPages = $pagination['totalPages'];
        $paginationBaseUrl = BASE_URL . 'admin/reports/index.php';
        $paginationQuery = ['start' => $filters['start'], 'end' => $filters['end'], 'section' => $section];
        $paginationLabel = 'Report detail pages';
        $paginationPageParameter = 'page';
        require __DIR__ . '/pagination.php';
      ?>
    </footer>
  <?php endif; ?>
</section>
