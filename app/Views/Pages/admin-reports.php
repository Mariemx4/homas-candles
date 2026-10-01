<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Page view for Admin Reports; it turns prepared application data into HTML.
 *
 * DATA FLOW: An HTTP entry point loads a bootstrap, obtains data from a controller, selects this file as $contentView, and loads a layout. Layout variables such as $escape are therefore injected into this view scope.
 */

$filters = $reportResult['filters'];
$money = static fn (mixed $value): string => number_format((float) $value, 3) . ' TND';
$number = static fn (mixed $value): string => number_format((float) $value, 3);
$humanize = static fn (mixed $value): string => ucwords(strtolower(str_replace('_', ' ', (string) $value)));
$formatDateTime = static function (mixed $value): string {
    if (!is_string($value) || $value === '') {
        return 'Not recorded';
    }
    try {
        return (new DateTimeImmutable($value))->format('d M Y, H:i');
    } catch (Throwable) {
        return 'Not available';
    }
};
$sectionLabels = ['orders' => 'Orders', 'payments' => 'Verified payments', 'requests' => 'Custom requests', 'materials' => 'Material use and waste'];
?>
<header class="admin-page-header admin-page-header--orders">
  <div><h1>Reports</h1><p class="admin-page-header__summary">Auditable totals backed by bounded detail queries and immutable transaction records.</p></div>
  <?php if ($reportResult['loadError'] === null): ?><div class="admin-result-summary"><strong><?php echo $escape($reportResult['pagination']['total']); ?></strong><span>supporting rows</span></div><?php endif; ?>
</header>

<?php if (is_string($reportResult['loadError'])): ?>
  <?php $alertTone = 'error'; $alertTitle = 'Unable to load reports'; $alertMessage = $reportResult['loadError']; require __DIR__ . '/../Components/Admin/alert.php'; ?>
<?php elseif ($filters['errors'] !== []): ?>
  <?php $alertTone = 'warning'; $alertTitle = 'Report filters adjusted'; $alertMessage = implode(' ', $filters['errors']); require __DIR__ . '/../Components/Admin/alert.php'; ?>
<?php endif; ?>

<section class="admin-filter-panel" aria-labelledby="report-filters-title">
  <div class="admin-filter-panel__heading"><h2 id="report-filters-title">Date range and detail</h2><a href="<?php echo BASE_URL . 'admin/reports/index.php'; ?>">Reset</a></div>
  <!-- FORM PURPOSE: Submit filter form with GET; the receiving PHP route must validate the values before using them. -->
  <form class="admin-filter-form admin-report-filter-form" action="<?php echo BASE_URL . 'admin/reports/index.php'; ?>" method="get">
    <div class="admin-field"><label for="report-start">Start date</label><input id="report-start" type="date" name="start" value="<?php echo $escape($filters['start']); ?>" required></div>
    <div class="admin-field"><label for="report-end">End date</label><input id="report-end" type="date" name="end" value="<?php echo $escape($filters['end']); ?>" required></div>
    <div class="admin-field"><label for="report-section">Supporting detail</label><select id="report-section" name="section"><?php foreach ($sectionLabels as $value => $label): ?><option value="<?php echo $escape($value); ?>"<?php echo $filters['section'] === $value ? ' selected' : ''; ?>><?php echo $escape($label); ?></option><?php endforeach; ?></select></div>
    <div class="admin-filter-form__actions"><button class="admin-button" type="submit">Run report</button></div>
  </form>
</section>

<?php if ($reportResult['loadError'] === null): ?>
  <?php require __DIR__ . '/../Components/Admin/report-summary.php'; ?>
  <?php require __DIR__ . '/../Components/Admin/report-top-items.php'; ?>
  <?php require __DIR__ . '/../Components/Admin/report-detail.php'; ?>
  <?php require __DIR__ . '/../Components/Admin/report-definitions.php'; ?>
<?php endif; ?>
