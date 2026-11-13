<?php

declare(strict_types=1);

use App\View\AdminIcons;

$unavailable = is_string($dashboard['loadError'] ?? null);
$metrics = $dashboard['metrics'] ?? [];
$today = (string)($dashboard['today'] ?? date('Y-m-d'));
$cards = [['Requested orders', 'requestedOrders', 'orders', 'orders/index.php?status=REQUESTED', 'Ready for your review'], ['Due within 7 days', 'ordersDueSoon', 'today', 'orders/index.php?' . http_build_query(['status' => 'INCOMPLETE', 'date_from' => $today, 'date_to' => $dashboard['throughDate'] ?? $today]), 'Plan the week ahead'], ['Pending payments', 'pendingPayments', 'payments', 'payments/index.php?status=PENDING_VERIFICATION', 'Transfers to verify'], ['Open custom requests', 'openRequests', 'requests', 'requests/index.php?status=OPEN', 'Conversations in progress'], ['Low stock materials', 'lowStockMaterials', 'stock', 'inventory/materials.php?stock_status=low', 'Check your supplies']];
$task = static function (string $icon, string $title, string $description, string $label, string $url, bool $warning = false) use ($escape): void { ?>
  <li class="admin-task-row"><span class="admin-task-row__icon<?php echo $warning ? ' admin-task-row__icon--warning' : ''; ?>"><?php echo AdminIcons::render($icon); ?></span>
    <div><strong><?php echo $escape($title); ?></strong><small><?php echo $escape($description); ?></small></div><a href="<?php echo $escape($url); ?>"><?php echo $escape($label); ?> <?php echo AdminIcons::render('arrow'); ?></a>
  </li>
<?php };
$suggestion = ['title' => 'Ready for your next customer.', 'detail' => 'Create an order, record a custom brief or plan upcoming production.', 'url' => BASE_URL . 'admin/orders/create.php', 'label' => 'New order'];
if (!empty($dashboard['urgentActions'])) {
    $next = $dashboard['urgentActions'][0];
    $suggestion = ['title' => 'Start with ' . $next['title'], 'detail' => $next['deadline']['label'] . ' · ' . $next['detail'], 'url' => BASE_URL . 'admin/orders/detail.php?id=' . $next['id'], 'label' => 'Open priority'];
} elseif (!empty($dashboard['pendingPayments'])) {
    $next = $dashboard['pendingPayments'][0];
    $suggestion = ['title' => 'Review the transfer for ' . $next['order_number'], 'detail' => 'Confirm the D17 evidence so the order shows its correct paid balance.', 'url' => BASE_URL . 'admin/payments/review.php?id=' . $next['id'] . '&action=verify', 'label' => 'Review transfer'];
} elseif (!empty($dashboard['recentRequests'])) {
    $next = $dashboard['recentRequests'][0];
    $suggestion = ['title' => 'Continue with ' . $next['customer_name_snapshot'], 'detail' => 'Review the brief and its next conversation or agreement task.', 'url' => BASE_URL . 'admin/requests/detail.php?id=' . $next['id'], 'label' => 'Open request'];
}
if ($unavailable) {
    $suggestion = ['title' => 'Your working queues are temporarily unavailable.', 'detail' => 'Retry below to see current priorities.', 'url' => BASE_URL . 'admin/index.php', 'label' => 'Retry'];
}
?>
<header class="admin-hero">
  <div><span class="admin-eyebrow">YOUR HOM’AS WORKSPACE</span>
    <h1>First things first.</h1>
    <p class="admin-hero__next-label">Suggested next step</p>
    <h2><?php echo $escape($suggestion['title']); ?></h2>
    <p><?php echo $escape($suggestion['detail']); ?></p>
  </div>
  <div class="admin-hero__aside">
    <div class="admin-hero__date"><?php echo $escape(date('l', strtotime($today))); ?><strong><?php echo $escape(date('d F Y', strtotime($today))); ?></strong></div>
    <div class="admin-hero__actions"><a class="admin-button" href="<?php echo $escape($suggestion['url']); ?>"><?php echo $escape($suggestion['label']); ?> <?php echo AdminIcons::render('arrow'); ?></a><?php if ($suggestion['label'] !== 'New order'): ?><a class="admin-button admin-button--secondary" href="<?php echo BASE_URL; ?>admin/orders/create.php"><?php echo AdminIcons::render('plus'); ?>New order</a><?php endif; ?></div>
  </div>
</header>
<?php if ($unavailable): $alertTone = 'error';
    $alertTitle = 'Unable to load live dashboard data';
    $alertMessage = $dashboard['loadError'];
    require VIEW_PATH . '/Components/Admin/alert.php'; ?><p><a class="admin-button" href="<?php echo BASE_URL; ?>admin/index.php">Retry dashboard</a></p><?php endif; ?>
<section class="admin-stats" aria-label="Dashboard summary"><?php foreach ($cards as [$label, $key, $icon, $path, $note]): ?><article class="admin-stat"><a class="admin-stat__link" href="<?php echo $escape(BASE_URL . 'admin/' . $path); ?>" aria-label="<?php echo $escape($label . ': ' . ($unavailable ? 'Unavailable' : ($metrics[$key] ?? 0))); ?>"></a>
      <div class="admin-stat__heading">
        <p><?php echo $escape($label); ?></p><span aria-hidden="true"><?php echo AdminIcons::render($icon); ?></span>
      </div>
      <p class="admin-stat__value"><?php echo $unavailable ? '&mdash;' : $escape($metrics[$key] ?? 0); ?></p>
      <p class="admin-stat__note"><?php echo $unavailable ? 'Data unavailable' : $escape($note); ?></p>
    </article><?php endforeach; ?></section>
<?php if ($unavailable) {
    return;
} ?>
<div class="admin-today-columns">
  <div>
    <section class="admin-panel">
      <header class="admin-today-section-header">
        <h2>Your next priorities</h2><a href="<?php echo BASE_URL; ?>admin/orders/index.php?status=INCOMPLETE">All open orders ↗</a>
      </header>
      <ol class="admin-task-list">
        <?php foreach ($dashboard['urgentActions'] ?? [] as $action) {
            $task('orders', $action['title'], $action['kind'] . ' · ' . $action['deadline']['label'] . ' · ' . $action['detail'], 'Open order', BASE_URL . 'admin/orders/detail.php?id=' . $action['id'], $action['tone'] === 'danger');
        } ?>
      </ol><?php if (empty($dashboard['urgentActions'])): ?><div class="admin-quiet-empty"><strong>No urgent orders</strong>Nothing overdue or due in the next seven days.</div><?php endif; ?>
    </section>
    <section class="admin-panel">
      <header class="admin-today-section-header">
        <h2>Production, at a glance</h2><a href="<?php echo BASE_URL; ?>admin/production/queue.php">Open production ↗</a>
      </header>
      <ul class="admin-task-list">
        <?php foreach ($dashboard['productionChecks'] ?? [] as $row): $check = $row['readiness'];
            $reason = $check['missing'] !== [] ? 'Recipe needed: ' . implode(', ', $check['missing']) : ($check['shortages'] !== [] ? 'Supplies to review: ' . implode(', ', array_column($check['shortages'], 'name')) : ($check['items'] === 0 ? 'No production items recorded' : 'No current stock or recipe blockers'));
            $task('production', $row['order_number'], $reason, $check['ready'] ? 'View production' : 'Resolve blocker', BASE_URL . 'admin/orders/detail.php?id=' . $row['id'] . '#order-production', !$check['ready']);
        endforeach; ?>
      </ul><?php if (empty($dashboard['productionChecks'])): ?><div class="admin-quiet-empty"><strong>A clear production queue</strong>Confirmed work will appear here.</div><?php else: ?><p class="admin-panel__meta">Nearest eight orders. Availability is checked per order and rechecked when preparing; materials are not reserved by this preview.</p><?php endif; ?>
    </section>
    <section class="admin-panel admin-panel--table">
      <header class="admin-panel__header">
        <h2>Coming up</h2><a href="<?php echo BASE_URL; ?>admin/production/calendar.php"><?php echo AdminIcons::render('eye'); ?>View calendar</a>
      </header><?php $tableCaption = 'Upcoming orders';
$tableRows = $dashboard['upcomingOrders'] ?? [];
$tableColumns = [['label' => 'Order', 'key' => 'order_number', 'type' => 'link', 'route' => 'admin/orders/detail.php'], ['label' => 'Customer', 'key' => 'customer_name_snapshot'], ['label' => 'Required', 'key' => 'required_date', 'type' => 'date', 'contextKey' => 'deadline'], ['label' => 'Status', 'key' => 'order_status', 'type' => 'status']];
$emptyTitle = 'No upcoming orders';
$emptyMessage = 'Future orders will appear here.';
require VIEW_PATH . '/Components/Admin/data-table.php'; ?>
    </section>
  </div>
  <div>
    <section class="admin-panel">
      <header class="admin-today-section-header">
        <h2>Payments to review</h2><a href="<?php echo BASE_URL; ?>admin/payments/index.php?status=PENDING_VERIFICATION"><?php echo AdminIcons::render('eye'); ?>View all ↗</a>
      </header>
      <ul class="admin-task-list"><?php foreach ($dashboard['pendingPayments'] ?? [] as $payment) {
          $task('payments', $payment['order_number'], $payment['amount'] . ' TND · D17 transfer', 'Review transfer', BASE_URL . 'admin/payments/review.php?id=' . $payment['id'] . '&action=verify', true);
      } ?></ul><?php if (empty($dashboard['pendingPayments'])): ?><div class="admin-quiet-empty"><strong>All caught up</strong>No payments awaiting verification.</div><?php endif; ?>
    </section>
    <section class="admin-panel">
      <header class="admin-today-section-header">
        <h2>Custom requests</h2><a href="<?php echo BASE_URL; ?>admin/requests/index.php?status=OPEN"><?php echo AdminIcons::render('eye'); ?>View requests ↗</a>
      </header>
      <ul class="admin-task-list"><?php foreach ($dashboard['recentRequests'] ?? [] as $request): $agreed = $request['request_status'] === 'AGREED';
          $task('requests', $request['request_number'], $request['customer_name_snapshot'] . ' · ' . ($agreed ? 'Agreement recorded' : ucfirst(strtolower($request['request_status']))), $agreed ? 'Create order' : 'Continue', BASE_URL . 'admin/requests/' . ($agreed ? 'convert' : 'detail') . '.php?id=' . $request['id']);
      endforeach; ?></ul><?php if (empty($dashboard['recentRequests'])): ?><div class="admin-quiet-empty"><strong>No open custom requests</strong>New enquiries will appear here.</div><?php endif; ?>
    </section>
    <section class="admin-panel">
      <header class="admin-today-section-header">
        <h2>Supplies needing attention</h2><a href="<?php echo BASE_URL; ?>admin/inventory/materials.php?stock_status=low"><?php echo AdminIcons::render('eye'); ?>View stock ↗</a>
      </header>
      <ul class="admin-task-list"><?php foreach ($dashboard['lowStockMaterials'] ?? [] as $material) {
          $task('stock', $material['name'], $material['stock_quantity'] . ' ' . $material['unit'] . ' left; minimum ' . $material['minimum_stock_level'], 'Receive stock', BASE_URL . 'admin/inventory/materials.php?id=' . $material['id'] . '#movement-form-title', true);
      } ?></ul><?php if (empty($dashboard['lowStockMaterials'])): ?><div class="admin-quiet-empty"><strong><?php echo !empty($dashboard['unconfiguredMaterials']) ? 'No configured low-stock warnings' : 'Stock levels are healthy'; ?></strong>No material is at or below its configured minimum.</div><?php endif; ?><?php if (!empty($dashboard['unconfiguredMaterials'])): ?><p class="admin-panel__meta"><?php echo $escape($dashboard['unconfiguredMaterials']); ?> tracked materials have no minimum configured. <a href="<?php echo BASE_URL; ?>admin/inventory/materials.php?stock_status=unconfigured">Set minimum levels</a></p><?php endif; ?>
    </section>
  </div>
</div>