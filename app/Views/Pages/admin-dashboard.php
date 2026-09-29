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
$lowestTrackedStock = $metrics['lowestTrackedStock'] ?? null;
$lowestStockValue = '—';
$lowestStockNote = 'No active tracked materials';

// Replace the empty-state text only when the service returned a material row.
if (is_array($lowestTrackedStock)) {
    $lowestStockValue = number_format((float) $lowestTrackedStock['stock_quantity'], 3);
    $lowestStockNote = (string) $lowestTrackedStock['name'] . ' · ' . (string) $lowestTrackedStock['unit'];
}
// Store repeated card content as data. One foreach loop can then render every
// card through the same component instead of duplicating five HTML blocks.
$statCards = [
    [
        'label' => 'Requested orders',
        'value' => (string) ($metrics['requestedOrders'] ?? 0),
        'note' => 'Awaiting administrator review',
        'mark' => 'OR',
        'tone' => ((int) ($metrics['requestedOrders'] ?? 0)) > 0 ? 'warning' : 'default',
    ],
    [
        'label' => 'Due within 7 days',
        'value' => (string) ($metrics['ordersDueSoon'] ?? 0),
        'note' => 'Incomplete orders, including today',
        'mark' => '7D',
        'tone' => ((int) ($metrics['ordersDueSoon'] ?? 0)) > 0 ? 'danger' : 'default',
    ],
    [
        'label' => 'Pending payments',
        'value' => (string) ($metrics['pendingPayments'] ?? 0),
        'note' => 'Waiting for verification',
        'mark' => 'PY',
        'tone' => ((int) ($metrics['pendingPayments'] ?? 0)) > 0 ? 'warning' : 'default',
    ],
    [
        'label' => 'Open requests',
        'value' => (string) ($metrics['openRequests'] ?? 0),
        'note' => 'Not closed or converted',
        'mark' => 'CR',
        'tone' => 'default',
    ],
    [
        'label' => 'Lowest tracked stock',
        'value' => $lowestStockValue,
        'note' => $lowestStockNote,
        'mark' => 'ST',
        'tone' => 'default',
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

<!-- SECTION PURPOSE: Group the Dashboard summary content, states, and controls as one accessible page region. -->
<section class="admin-stats" aria-label="Dashboard summary">
  <?php // SECTION 3 — REUSABLE METRIC CARDS ?>
  <?php foreach ($statCards as $card): ?>
    <?php
      // Rename array fields to the explicit variables expected by stat-card.php.
      $statLabel = $card['label'];
      $statValue = $card['value'];
      $statNote = $card['note'];
      $statMark = $card['mark'];
      $statTone = $card['tone'];
      require __DIR__ . '/../Components/Admin/stat-card.php';
    ?>
  <?php endforeach; ?>
</section>

<div class="admin-dashboard-grid admin-dashboard-grid--primary">
  <!-- SECTION 4 — URGENT ORDERS AND INVENTORY SNAPSHOT -->
  <!-- SECTION PURPOSE: Group the Urgent actions content, states, and controls as one accessible page region. -->
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
        $emptyMessage = 'There are no incomplete orders due within the next seven days.';
        require __DIR__ . '/../Components/Admin/empty-state.php';
      ?>
    <?php else: ?>
      <ol class="admin-action-list">
        <?php foreach ($dashboard['urgentActions'] as $action): ?>
          <?php // `$escape` protects every value that originated outside this template. ?>
          <li class="admin-action admin-action--<?php echo $escape($action['tone']); ?>">
            <div class="admin-action__topline">
              <span class="admin-action__kind"><?php echo $escape($action['kind']); ?></span>
              <time datetime="<?php echo $escape($action['date']); ?>"><?php echo $escape(date('d M', strtotime((string) $action['date']))); ?></time>
            </div>
            <strong><?php echo $escape($action['title']); ?></strong>
            <span><?php echo $escape($action['detail']); ?></span>
            <?php $status = $action['status']; require __DIR__ . '/../Components/Admin/status-badge.php'; ?>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </section>

  <!-- SECTION PURPOSE: Group the Lowest tracked stock content, states, and controls as one accessible page region. -->
  <section class="admin-panel" aria-labelledby="stock-title">
    <header class="admin-panel__header">
      <div>
        <h2 id="stock-title">Lowest tracked stock</h2>
      </div>
    </header>
    <p class="admin-panel__intro">Ranked by quantity on hand. No low-stock threshold is configured.</p>
    <?php if (($dashboard['lowestStockMaterials'] ?? []) === []): ?>
      <?php
        $emptyTitle = 'No tracked stock';
        $emptyMessage = 'There are no active materials with stock tracking enabled.';
        require __DIR__ . '/../Components/Admin/empty-state.php';
      ?>
    <?php else: ?>
      <ol class="admin-stock-list">
        <?php foreach ($dashboard['lowestStockMaterials'] as $material): ?>
          <li>
            <span><strong><?php echo $escape($material['name']); ?></strong><small><?php echo $escape($material['sku']); ?></small></span>
            <span class="admin-stock-list__quantity"><?php echo $escape(number_format((float) $material['stock_quantity'], 3)); ?> <small><?php echo $escape($material['unit']); ?></small></span>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </section>
</div>

<!-- SECTION PURPOSE: Group the Upcoming orders content, states, and controls as one accessible page region. -->
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
        ['label' => 'Order', 'key' => 'order_number'],
        ['label' => 'Customer', 'key' => 'customer_name_snapshot'],
        ['label' => 'Required', 'key' => 'required_date', 'type' => 'date'],
        ['label' => 'Fulfilment', 'key' => 'delivery_method', 'type' => 'humanized'],
        ['label' => 'Total', 'key' => 'total_amount', 'type' => 'money', 'currencyKey' => 'currency'],
        ['label' => 'Status', 'key' => 'order_status', 'type' => 'status'],
    ];
    $emptyTitle = 'No upcoming orders';
    $emptyMessage = 'Incomplete orders with future required dates will appear here.';
    require __DIR__ . '/../Components/Admin/data-table.php';
  ?>
</section>

<div class="admin-dashboard-grid admin-dashboard-grid--secondary">
  <!-- SECTION 6 — RECENT REQUESTS AND PAYMENTS -->
  <!-- SECTION PURPOSE: Group the Recent custom requests content, states, and controls as one accessible page region. -->
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
          ['label' => 'Request', 'key' => 'request_number'],
          ['label' => 'Customer', 'key' => 'customer_name_snapshot'],
          ['label' => 'Required', 'key' => 'required_date', 'type' => 'date'],
          ['label' => 'Status', 'key' => 'request_status', 'type' => 'status'],
      ];
      $emptyTitle = 'No open custom requests';
      $emptyMessage = 'New requests that still need follow-up will appear here.';
      require __DIR__ . '/../Components/Admin/data-table.php';
    ?>
  </section>

  <!-- SECTION PURPOSE: Group the Pending payments content, states, and controls as one accessible page region. -->
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
          ['label' => 'Order', 'key' => 'order_number'],
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
