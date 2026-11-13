<?php

declare(strict_types=1);

use App\View\Presentation;

$filters = $reportResult['filters'];
$money = Presentation::money(...);
$number = static fn (mixed $value): string => number_format((float) $value, 3);
$humanize = Presentation::humanize(...);
$formatDateTime = static fn (mixed $value): string => Presentation::timestamp($value, 'Not recorded', 'Not available');
$sectionLabels = ['orders' => 'Orders', 'payments' => 'Verified payments', 'requests' => 'Custom requests', 'materials' => 'Material use and waste'];
?>
<header class="admin-page-header admin-page-header--orders">
  <div>
    <h1>Reports</h1>
    <p class="admin-page-header__summary">Understand your orders, money received and material use for the period you choose.</p>
  </div>
  <?php if ($reportResult['loadError'] === null): ?><div class="admin-result-summary"><strong><?php echo $escape($reportResult['pagination']['total']); ?></strong><span>records in this view</span></div><?php endif; ?>
</header>

<?php if (is_string($reportResult['loadError'])): ?>
  <?php $alertTone = 'error';
    $alertTitle = 'Unable to load reports';
    $alertMessage = $reportResult['loadError'];
    require VIEW_PATH . '/Components/Admin/alert.php'; ?>
<?php elseif ($filters['errors'] !== []): ?>
  <?php $alertTone = 'warning';
        $alertTitle = 'Report filters adjusted';
        $alertMessage = implode(' ', $filters['errors']);
        require VIEW_PATH . '/Components/Admin/alert.php'; ?>
<?php endif; ?>

<section class="admin-filter-panel" aria-labelledby="report-filters-title">
  <div class="admin-filter-panel__heading">
    <h2 id="report-filters-title">Choose a period</h2><a href="<?php echo BASE_URL . 'admin/reports/index.php'; ?>">Reset</a>
  </div>
  <form class="admin-filter-form admin-report-filter-form" action="<?php echo BASE_URL . 'admin/reports/index.php'; ?>" method="get">
    <div class="admin-field"><label for="report-start">Start date</label><input id="report-start" type="date" name="start" value="<?php echo $escape($filters['start']); ?>" required></div>
    <div class="admin-field"><label for="report-end">End date</label><input id="report-end" type="date" name="end" value="<?php echo $escape($filters['end']); ?>" required></div>
    <div class="admin-field"><label for="report-section">What would you like to review?</label><select id="report-section" name="section"><?php foreach ($sectionLabels as $value => $label): ?><option value="<?php echo $escape($value); ?>" <?php echo $filters['section'] === $value ? ' selected' : ''; ?>><?php echo $escape($label); ?></option><?php endforeach; ?></select></div>
    <div class="admin-filter-form__actions"><button class="admin-button" type="submit">Run report</button></div>
  </form>
</section>

<?php if ($reportResult['loadError'] === null): ?>
  <?php require VIEW_PATH . '/Components/Admin/report-summary.php'; ?>
  <?php require VIEW_PATH . '/Components/Admin/report-top-items.php'; ?>
  <?php require VIEW_PATH . '/Components/Admin/report-detail.php'; ?>
  <?php require VIEW_PATH . '/Components/Admin/report-definitions.php'; ?>
<?php endif; ?>