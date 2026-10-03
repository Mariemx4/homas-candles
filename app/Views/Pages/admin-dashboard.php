<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Page view for Admin Dashboard; it turns prepared application data into HTML.
 *
 * DATA FLOW: An HTTP entry point loads a bootstrap, obtains data from a controller, selects this file as $contentView, and loads a layout. Layout variables such as $escape are therefore injected into this view scope.
 */



/** @var callable(mixed): string $escape */
/** @var array<string, mixed> $dashboard */

// SECTION 1 — PREPARE SUMMARY CARDS
// Pull nested metrics into a shorter local variable. The empty-array fallback
// lets this view render the controller's safe error response without warnings.
$metrics = $dashboard['metrics'] ?? [];
$dashboardDate = (string) ($dashboard['today'] ?? date('Y-m-d'));
// Store repeated card content as data. One foreach loop can then render every
// card through the same component instead of duplicating five HTML blocks.
$statCards = [
  [
    'label' => 'Requested orders',
    'href' => BASE_URL . 'admin/orders/index.php?' . http_build_query(['status' => 'REQUESTED']),
    'value' => (string) ($metrics['requestedOrders'] ?? 0),
    'note' => 'Awaiting administrator review',
    'mark' => 'OR',
    'tone' => ((int) ($metrics['requestedOrders'] ?? 0)) > 0 ? 'warning' : 'default',
  ],
  [
    'label' => 'Due within 7 days',
    'href' => BASE_URL . 'admin/orders/index.php?' . http_build_query(['status' => 'INCOMPLETE', 'date_from' => $dashboardDate, 'date_to' => $dashboard['throughDate'] ?? date('Y-m-d', strtotime($dashboardDate . ' +7 days'))]),
    'value' => (string) ($metrics['ordersDueSoon'] ?? 0),
    'note' => 'Incomplete orders, including today',
    'mark' => '7D',
    'tone' => ((int) ($metrics['ordersDueSoon'] ?? 0)) > 0 ? 'danger' : 'default',
  ],
  [
    'label' => 'Pending payments',
    'href' => BASE_URL . 'admin/payments/index.php?' . http_build_query(['status' => 'PENDING_VERIFICATION']),
    'value' => (string) ($metrics['pendingPayments'] ?? 0),
    'note' => 'Waiting for verification',
    'mark' => 'PY',
    'tone' => ((int) ($metrics['pendingPayments'] ?? 0)) > 0 ? 'warning' : 'default',
  ],
  [
    'label' => 'Open custom requests',
    'href' => BASE_URL . 'admin/requests/index.php?' . http_build_query(['status' => 'OPEN']),
    'value' => (string) ($metrics['openRequests'] ?? 0),
    'note' => 'Not closed or converted',
    'mark' => 'CR',
    'tone' => 'default',
  ],
  [
    'label' => 'Low stock materials',
    'href' => BASE_URL . 'admin/inventory/materials.php?' . http_build_query(['stock_status' => 'low']),
    'value' => (string) ($metrics['lowStockMaterials'] ?? 0),
    'note' => 'Materials requiring restocking',
    'mark' => 'ST',
    'tone' => ((int) ($metrics['lowStockMaterials'] ?? 0)) > 0 ? 'warning' : 'default',
  ],
];
?>
<header class="admin-page-header">
  <!-- SECTION 2 — PAGE INTRODUCTION AND CURRENT DASHBOARD DATE -->
  <div>
    <h1>Good to see you, <?php echo $escape((string) ($administrator['first_name'] ?? 'Administrator')); ?>.</h1>
    <p class="admin-page-header__summary">A focused view of work that may need your attention today.</p>
  </div>
  <div class="admin-date-card" aria-label="Dashboard date">
    <span><?php echo $escape(date('l', strtotime((string) ($dashboard['today'] ?? 'today')))); ?></span>
    <time datetime="<?php echo $escape($dashboard['today'] ?? date('Y-m-d')); ?>"><?php echo $escape(date('d M Y', strtotime((string) ($dashboard['today'] ?? 'today')))); ?></time>
  </div>
</header>

<?php if (is_string($dashboard['loadError'] ?? null)): ?>
  <?php
  // The alert component expects these four variables from its parent scope.
  $alertTone = 'error';
  $alertTitle = 'Unable to load live dashboard data';
  $alertMessage = $dashboard['loadError'];
  require __DIR__ . '/../Components/Admin/alert.php';
  ?>
<?php endif; ?>

<section class="admin-stats" aria-label="Dashboard summary">
  <?php // SECTION 3 — REUSABLE METRIC CARDS
  ?>
  <?php foreach ($statCards as $card): ?>
    <?php
    // Rename array fields to the explicit variables expected by stat-card.php.
    $statLabel = $card['label'];
    $statValue = $card['value'];
    $statNote = $card['note'];
    $statMark = $card['mark'];
    $statTone = $card['tone'];
    $statHref = $card['href'];
    require __DIR__ . '/../Components/Admin/stat-card.php';
    ?>
  <?php endforeach; ?>
</section>

<div class="admin-dashboard-grid">
  <!-- SECTION 4 — URGENT ORDERS -->
  <section class="admin-panel admin-panel--urgent" aria-labelledby="urgent-actions-title">
    <header class="admin-panel__header">
      <div>
        <h2 id="urgent-actions-title">Urgent actions</h2>
      </div>
      <span class="admin-panel__count"><?php echo $escape(count($dashboard['urgentActions'] ?? [])); ?></span>
    </header>

    <?php if (($dashboard['urgentActions'] ?? []) === []): ?>
      <?php
      // The same empty-state component is reused with context-specific text.
      $emptyTitle = 'No urgent orders';
      $emptyMessage = 'There are no overdue or incomplete orders due within the next seven days.';
      require __DIR__ . '/../Components/Admin/empty-state.php';
      ?>
    <?php else: ?>
      <ol class="admin-action-list">
        <?php foreach ($dashboard['urgentActions'] as $action): ?>
          <?php // `$escape` protects every value that originated outside this template.
          ?>
          <li class="admin-action admin-action--<?php echo $escape($action['tone']); ?>">
            <div class="admin-action__topline">
              <span class="admin-action__kind"><?php echo $escape($action['kind']); ?></span>
              <time datetime="<?php echo $escape($action['date']); ?>"><?php echo $escape(date('d M', strtotime((string) $action['date'])) . ' · ' . $action['deadline']['label']); ?></time>
            </div>
            <strong><?php echo $escape($action['title']); ?></strong>
            <span><?php echo $escape($action['detail']); ?></span>
            <?php $status = $action['status'];
            require __DIR__ . '/../Components/Admin/status-badge.php'; ?>
            <a class="admin-table__primary-link" href="<?php echo $escape(BASE_URL . 'admin/orders/detail.php?' . http_build_query(['id' => $action['id']])); ?>">View order<span class="admin-visually-hidden"> <?php echo $escape($action['title']); ?></span></a>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </section>


</div>

<section class="admin-panel admin-panel--table" aria-labelledby="upcoming-orders-title">
  <!-- SECTION 5 — UPCOMING ORDERS TABLE -->
  <header class="admin-panel__header">
    <div>
      <h2 id="upcoming-orders-title">Upcoming orders</h2>
    </div>
    <span class="admin-panel__meta">Nearest required dates first</span>
  </header>
  <?php
  // The generic table component reads column metadata. `type` tells it how to
  // format dates, money, humanized enum values, and status badges.
  $tableCaption = 'Upcoming incomplete orders';
  $tableRows = $dashboard['upcomingOrders'] ?? [];
  $tableColumns = [
    ['label' => 'Order', 'key' => 'order_number', 'type' => 'link', 'route' => 'admin/orders/detail.php'],
    ['label' => 'Customer', 'key' => 'customer_name_snapshot'],
    ['label' => 'Required', 'key' => 'required_date', 'type' => 'date', 'contextKey' => 'deadline'],
    ['label' => 'Fulfilment', 'key' => 'delivery_method', 'type' => 'humanized'],
    ['label' => 'Total', 'key' => 'total_amount', 'type' => 'money', 'currencyKey' => 'currency'],
    ['label' => 'Status', 'key' => 'order_status', 'type' => 'status'],
  ];
  $emptyTitle = 'No upcoming orders';
  $emptyMessage = 'Incomplete orders with future required dates will appear here.';
  require __DIR__ . '/../Components/Admin/data-table.php';
  ?>
</section>

  <section class="admin-panel" aria-labelledby="stock-title">
    <header class="admin-panel__header">
      <div>
        <h2 id="stock-title">Low stock materials</h2>
      </div>
      <a class="admin-table__primary-link" href="<?php echo $escape(BASE_URL . 'admin/inventory/materials.php?stock_status=low'); ?>">View all low stock</a>
    </header>
    <p class="admin-panel__intro">Active tracked materials at or below their own minimum stock level.</p>
    <?php if (($dashboard['unconfiguredMaterials'] ?? 0) > 0): ?>
      <p class="admin-panel__intro"><?php echo $escape($dashboard['unconfiguredMaterials']); ?> tracked materials have no minimum configured and are excluded from warnings. <a href="<?php echo $escape(BASE_URL . 'admin/inventory/materials.php?stock_status=unconfigured'); ?>">Configure minimum levels</a></p>
    <?php endif; ?>
    <?php if (($dashboard['lowStockMaterials'] ?? []) === []): ?>
      <?php
      $emptyTitle = is_string($dashboard['loadError'] ?? null) ? 'Stock data unavailable' : (($dashboard['unconfiguredMaterials'] ?? 0) > 0 ? 'No configured low-stock warnings' : 'Stock levels are healthy');
      $emptyMessage = is_string($dashboard['loadError'] ?? null) ? 'Stock levels could not be checked.' : 'No tracked materials with a configured minimum are currently at or below their minimum stock level.';
      require __DIR__ . '/../Components/Admin/empty-state.php';
      ?>
    <?php else: ?>
      <ol class="admin-stock-list">
        <?php foreach ($dashboard['lowStockMaterials'] as $material): ?>
          <li>
            <span><strong><a class="admin-table__primary-link" href="<?php echo $escape(BASE_URL . 'admin/inventory/materials.php?' . http_build_query(['id' => $material['id']])); ?>"><?php echo $escape($material['name']); ?></a></strong><small><?php echo $escape($material['sku']); ?></small></span>
            <span class="admin-stock-list__quantity"><?php echo $escape(number_format((float) $material['stock_quantity'], 3) . ' ' . $material['unit']); ?><small>Minimum <?php echo $escape(number_format((float) $material['minimum_stock_level'], 3) . ' ' . $material['unit']); ?></small><span class="admin-badge admin-badge--warning">Low stock</span></span>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </section>

<div class="admin-dashboard-grid admin-dashboard-grid--secondary">
  <!-- SECTION 6 — RECENT REQUESTS AND PAYMENTS -->
  <section class="admin-panel admin-panel--table" aria-labelledby="requests-title">
    <header class="admin-panel__header">
      <div>
        <h2 id="requests-title">Recent custom requests</h2>
      </div>
    </header>
    <?php
    // Prepare a second configuration for the same generic data-table partial.
    $tableCaption = 'Recent open custom requests';
    $tableRows = $dashboard['recentRequests'] ?? [];
    $tableColumns = [
      ['label' => 'Request', 'key' => 'request_number', 'type' => 'link', 'route' => 'admin/requests/detail.php'],
      ['label' => 'Customer', 'key' => 'customer_name_snapshot'],
      ['label' => 'Summary', 'key' => 'summary'],
      ['label' => 'Required', 'key' => 'required_date', 'type' => 'date', 'contextKey' => 'deadline'],
      ['label' => 'Status', 'key' => 'request_status', 'type' => 'status'],
    ];
    $emptyTitle = 'No open custom requests';
    $emptyMessage = 'New requests that still need follow-up will appear here.';
    require __DIR__ . '/../Components/Admin/data-table.php';
    ?>
  </section>

  <section class="admin-panel admin-panel--table" aria-labelledby="payments-title">
    <header class="admin-panel__header">
      <div>
        <h2 id="payments-title">Pending payments</h2>
      </div>
    </header>
    <?php
    // Payment rows use their currency column when formatting the amount.
    $tableCaption = 'Payments pending administrator verification';
    $tableRows = $dashboard['pendingPayments'] ?? [];
    $tableColumns = [
      ['label' => 'Order', 'key' => 'order_number', 'type' => 'link', 'route' => 'admin/payments/order.php', 'parameter' => 'order_id', 'idKey' => 'order_id'],
      ['label' => 'Type', 'key' => 'payment_type', 'type' => 'humanized'],
      ['label' => 'Amount', 'key' => 'amount', 'type' => 'money', 'currencyKey' => 'currency'],
      ['label' => 'Status', 'key' => 'payment_status', 'type' => 'status'],
    ];
    $emptyTitle = 'No payments to verify';
    $emptyMessage = 'Payments awaiting administrator verification will appear here.';
    require __DIR__ . '/../Components/Admin/data-table.php';
    ?>
  </section>
</div>
