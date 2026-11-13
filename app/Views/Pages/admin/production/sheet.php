<?php

declare(strict_types=1);

use App\View\Presentation;
use App\Services\ProductionService;

$filters = $productionSheet['filters'];
$filterOptions = $productionSheet['filterOptions'];
$humanize = Presentation::humanize(...);
$formatDate = static function (mixed $value): string {
    if (!is_string($value) || $value === '') {
        return 'Not set';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable ? $date->format('d M Y') : 'Not set';
};
$formatQuantity = static fn (mixed $quantity, mixed $unit): string => number_format((float) $quantity, 3) . ' ' . (string) $unit;
$describeGroup = static function (array $group): string {
    $parts = array_filter([$group['color_name'], $group['scent_name']]);
    return $parts === [] ? '—' : implode(' · ', $parts);
};
?>
<header class="admin-page-header">
  <div>
    <h1>Preparation sheet</h1>
    <p class="admin-page-header__summary">Required products, materials, and blockers for the selected schedule window.</p>
  </div>
</header>

<?php require VIEW_PATH . '/Components/Admin/production-tabs.php'; ?>

<?php if (is_string($productionSheet['loadError'])): ?>
  <?php
    $alertTone = 'error';
    $alertTitle = 'Unable to load the preparation sheet';
    $alertMessage = $productionSheet['loadError'];
    require VIEW_PATH . '/Components/Admin/alert.php';
    ?>
<?php else: ?>
  <?php if ($productionSheet['filtersAdjusted']): ?>
    <?php
        $alertTone = 'warning';
      $alertTitle = 'Some filters were reset';
      $alertMessage = 'One or more unsupported filter or date values were ignored safely.';
      require VIEW_PATH . '/Components/Admin/alert.php';
      ?>
  <?php endif; ?>
  <?php if ($productionSheet['capped']): ?>
    <?php
        $alertTone = 'warning';
      $alertTitle = 'Showing a partial sheet';
      $alertMessage = 'This window matches ' . $productionSheet['totalMatching'] . ' orders. Only the first ' . $productionSheet['orderCount'] . ' are included — narrow the date range or filters for a complete sheet.';
      require VIEW_PATH . '/Components/Admin/alert.php';
      ?>
  <?php endif; ?>

  <section class="admin-filter-panel" aria-labelledby="sheet-filters-title">
    <div class="admin-filter-panel__heading">
      <h2 id="sheet-filters-title">Schedule window and filters</h2>
      <a href="<?php echo BASE_URL . 'admin/production/sheet.php'; ?>">Reset to today</a>
    </div>
    <form class="admin-filter-form" action="<?php echo BASE_URL . 'admin/production/sheet.php'; ?>" method="get">
      <div class="admin-field">
        <label for="sheet-date-from">From</label>
        <input id="sheet-date-from" name="date_from" type="date" value="<?php echo $escape($productionSheet['dateFrom']); ?>">
      </div>
      <div class="admin-field">
        <label for="sheet-date-to">Through</label>
        <input id="sheet-date-to" name="date_to" type="date" value="<?php echo $escape($productionSheet['dateTo']); ?>">
      </div>
      <div class="admin-field">
        <label for="sheet-status">Status</label>
        <select id="sheet-status" name="status">
          <option value="">All queue statuses</option>
          <?php foreach (ProductionService::STATUSES as $statusOption): ?>
            <option value="<?php echo $escape($statusOption); ?>"<?php echo $filters['status'] === $statusOption ? ' selected' : ''; ?>><?php echo $escape($humanize($statusOption)); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-field">
        <label for="sheet-method">Fulfilment</label>
        <select id="sheet-method" name="method">
          <option value="">All methods</option>
          <?php foreach (ProductionService::METHODS as $methodOption): ?>
            <option value="<?php echo $escape($methodOption); ?>"<?php echo $filters['method'] === $methodOption ? ' selected' : ''; ?>><?php echo $escape($humanize($methodOption)); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-field">
        <label for="sheet-product">Product</label>
        <select id="sheet-product" name="product">
          <option value="">All products</option>
          <?php foreach ($filterOptions['products'] as $product): ?>
            <option value="<?php echo $escape($product['id']); ?>"<?php echo (string) $filters['product_id'] === (string) $product['id'] ? ' selected' : ''; ?>><?php echo $escape($product['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-field">
        <label for="sheet-scent">Scent</label>
        <select id="sheet-scent" name="scent">
          <option value="">All scents</option>
          <?php foreach ($filterOptions['scents'] as $scent): ?>
            <option value="<?php echo $escape($scent['id']); ?>"<?php echo (string) $filters['scent_id'] === (string) $scent['id'] ? ' selected' : ''; ?>><?php echo $escape($scent['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-field">
        <label for="sheet-color">Color</label>
        <select id="sheet-color" name="color">
          <option value="">All colors</option>
          <?php foreach ($filterOptions['colors'] as $color): ?>
            <option value="<?php echo $escape($color['id']); ?>"<?php echo (string) $filters['color_id'] === (string) $color['id'] ? ' selected' : ''; ?>><?php echo $escape($color['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-filter-form__actions">
        <button class="admin-button" type="submit">Apply filters</button>
        <?php // URL IDIOM: http_build_query safely encodes each query-string value.?>
        <a class="admin-button admin-button--secondary" href="<?php echo $escape(BASE_URL . 'admin/production/sheet-print.php?' . http_build_query($productionSheet['filterQuery'])); ?>">Print preparation sheet</a>
      </div>
    </form>
  </section>

  <section class="admin-stats admin-print-hide" aria-label="Preparation sheet summary">
    <?php
        $statCards = [
            ['label' => 'Orders in scope', 'value' => (string) $productionSheet['orderCount'], 'note' => 'Confirmed and preparing', 'mark' => 'OR', 'tone' => 'default'],
            ['label' => 'Products to produce', 'value' => (string) count($productionSheet['toProduce']), 'note' => 'Distinct groups', 'mark' => 'PD', 'tone' => 'default'],
            ['label' => 'Sets to assemble', 'value' => (string) count($productionSheet['toAssemble']), 'note' => 'Distinct groups', 'mark' => 'AS', 'tone' => 'default'],
            ['label' => 'Missing recipes', 'value' => (string) count($productionSheet['missingRecipeProducts']), 'note' => 'Products with no actual recipe', 'mark' => '!', 'tone' => count($productionSheet['missingRecipeProducts']) > 0 ? 'danger' : 'default'],
            ['label' => 'Material shortages', 'value' => (string) count($productionSheet['shortages']), 'note' => 'Tracked materials short of stock', 'mark' => 'SH', 'tone' => count($productionSheet['shortages']) > 0 ? 'danger' : 'default'],
        ];
?>
    <?php foreach ($statCards as $card): ?>
      <?php
    $statLabel = $card['label'];
        $statValue = $card['value'];
        $statNote = $card['note'];
        $statMark = $card['mark'];
        $statTone = $card['tone'];
        require VIEW_PATH . '/Components/Admin/stat-card.php';
        ?>
    <?php endforeach; ?>
  </section>

  <?php if ($productionSheet['missingRecipeProducts'] !== []): ?>
    <?php
        $alertTone = 'error';
      $alertTitle = 'Missing recipe data';
      $alertMessage = 'These products have no actual recipe and cannot be checked for material feasibility: '
          . implode(', ', array_map(static fn (array $p): string => (string) $p['name'], $productionSheet['missingRecipeProducts'])) . '.';
      require VIEW_PATH . '/Components/Admin/alert.php';
      ?>
  <?php endif; ?>

  <?php if ($productionSheet['orphanedItemCount'] > 0): ?>
    <?php
        $alertTone = 'warning';
      $alertTitle = 'Some order items are excluded';
      $alertMessage = $productionSheet['orphanedItemCount'] . ' order item(s) in this window have no saved production recipe and are excluded from production grouping. Confirm their historical or agreed custom recipes from the order page.';
      require VIEW_PATH . '/Components/Admin/alert.php';
      ?>
  <?php endif; ?>

  <section class="admin-panel admin-panel--table" aria-labelledby="produce-title">
    <header class="admin-panel__header">
      <div>
        <h2 id="produce-title">Products to produce</h2>
      </div>
      <span class="admin-panel__count"><?php echo $escape(count($productionSheet['toProduce'])); ?></span>
    </header>
    <?php if ($productionSheet['toProduce'] === []): ?>
      <?php
          $emptyTitle = 'Nothing to produce';
        $emptyMessage = 'No production is required for this schedule window.';
        require VIEW_PATH . '/Components/Admin/empty-state.php';
        ?>
    <?php else: ?>
      <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable products to produce table">
        <table class="admin-table">
          <caption class="admin-visually-hidden">Products to produce</caption>
          <thead><tr><th scope="col">Product</th><th scope="col">Color / scent</th><th scope="col">Customization</th><th scope="col">Quantity</th><th scope="col">Orders</th></tr></thead>
          <tbody>
            <?php foreach ($productionSheet['toProduce'] as $group): ?>
              <?php // A custom-recipe group is made from its own recipe, never from the catalogue one.?>
              <?php $groupMissingRecipe = empty($group['custom_recipe']) && in_array($group['product_id'], $productionSheet['missingRecipeProductIds'], true); ?>
              <tr<?php echo $groupMissingRecipe ? ' class="admin-row--warning"' : ''; ?>>
                <td>
                  <?php echo $escape($group['product_name']); ?>
                  <?php if ($groupMissingRecipe): ?>
                    <small><span class="admin-badge admin-badge--danger">No recipe</span></small>
                  <?php elseif (!empty($group['custom_recipe'])): ?>
                    <small><span class="admin-badge admin-badge--info"><?php echo ($group['recipe_source'] ?? '') === 'CATALOGUE' ? 'Saved catalogue recipe' : 'Custom recipe'; ?></span></small>
                  <?php endif; ?>
                </td>
                <td><?php echo $escape($describeGroup($group)); ?></td>
                <td><?php echo $escape($group['customization_notes'] ?? '—'); ?></td>
                <td><?php echo $escape($group['quantity']); ?></td>
                <td><?php echo $escape(implode(', ', $group['order_numbers'])); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="admin-panel admin-panel--table" aria-labelledby="assemble-title">
    <header class="admin-panel__header">
      <div>
        <h2 id="assemble-title">Sets to assemble</h2>
      </div>
      <span class="admin-panel__count"><?php echo $escape(count($productionSheet['toAssemble'])); ?></span>
    </header>
    <?php if ($productionSheet['toAssemble'] === []): ?>
      <?php
          $emptyTitle = 'No sets to assemble';
        $emptyMessage = 'No set products are due in this schedule window.';
        require VIEW_PATH . '/Components/Admin/empty-state.php';
        ?>
    <?php else: ?>
      <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable sets to assemble table">
        <table class="admin-table">
          <caption class="admin-visually-hidden">Sets to assemble</caption>
          <thead><tr><th scope="col">Set</th><th scope="col">Color / scent</th><th scope="col">Customization</th><th scope="col">Quantity</th><th scope="col">Orders</th></tr></thead>
          <tbody>
            <?php foreach ($productionSheet['toAssemble'] as $group): ?>
              <tr>
                <td><?php echo $escape($group['product_name']); ?></td>
                <td><?php echo $escape($describeGroup($group)); ?></td>
                <td><?php echo $escape($group['customization_notes'] ?? '—'); ?></td>
                <td><?php echo $escape($group['quantity']); ?></td>
                <td><?php echo $escape(implode(', ', $group['order_numbers'])); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="admin-panel admin-panel--table" aria-labelledby="feasibility-title">
    <header class="admin-panel__header">
      <div>
        <h2 id="feasibility-title">Tracked material requirements</h2>
      </div>
      <span class="admin-panel__count"><?php echo $escape(count($productionSheet['materials'])); ?></span>
    </header>
    <p class="admin-panel__intro">Calculated live from actual recipe inputs and current tracked stock. Only tracked, consumable materials are shown.</p>
    <?php if ($productionSheet['materials'] === []): ?>
      <?php
          $emptyTitle = 'No tracked materials required';
        $emptyMessage = 'No tracked, consumable materials are required for this schedule window.';
        require VIEW_PATH . '/Components/Admin/empty-state.php';
        ?>
    <?php else: ?>
      <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable material requirements table">
        <table class="admin-table">
          <caption class="admin-visually-hidden">Tracked material requirements</caption>
          <thead><tr><th scope="col">Material</th><th scope="col">Required</th><th scope="col">In stock</th><th scope="col">Shortfall</th></tr></thead>
          <tbody>
            <?php foreach ($productionSheet['materials'] as $material): ?>
              <tr<?php echo $material['shortfall'] !== null ? ' class="admin-row--warning"' : ''; ?>>
                <td><?php echo $escape($material['name']); ?><small><?php echo $escape($material['sku']); ?></small></td>
                <td><?php echo $escape($formatQuantity($material['required_quantity'], $material['unit'])); ?></td>
                <td><?php echo $escape($formatQuantity($material['stock_quantity'], $material['unit'])); ?></td>
                <td>
                  <?php if ($material['shortfall'] !== null): ?>
                    <span class="admin-badge admin-badge--danger">Short by <?php echo $escape($formatQuantity($material['shortfall'], $material['unit'])); ?></span>
                  <?php else: ?>
                    <span class="admin-badge admin-badge--success">Available</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="admin-panel admin-panel--table admin-print-hide" aria-labelledby="scope-orders-title">
    <header class="admin-panel__header">
      <div>
        <h2 id="scope-orders-title">Orders included in this sheet</h2>
      </div>
      <span class="admin-panel__count"><?php echo $escape($productionSheet['orderCount']); ?></span>
    </header>
    <?php if ($productionSheet['orders'] === []): ?>
      <?php
          $emptyTitle = 'No orders in this window';
        $emptyMessage = 'No confirmed or preparing orders fall within the selected schedule window and filters.';
        require VIEW_PATH . '/Components/Admin/empty-state.php';
        ?>
    <?php else: ?>
      <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable orders in this sheet table">
        <table class="admin-table">
          <caption class="admin-visually-hidden">Orders included in this sheet</caption>
          <thead><tr><th scope="col">Order</th><th scope="col">Customer</th><th scope="col">Required</th><th scope="col">Fulfilment</th><th scope="col">Status</th></tr></thead>
          <tbody>
            <?php foreach ($productionSheet['orders'] as $order): ?>
              <?php // URL IDIOM: http_build_query safely encodes each query-string value.?>
              <?php $detailUrl = BASE_URL . 'admin/orders/detail.php?' . http_build_query(['id' => $order['id']]); ?>
              <tr>
                <td><a class="admin-table__primary-link" href="<?php echo $escape($detailUrl); ?>"><?php echo $escape($order['order_number']); ?></a></td>
                <td><?php echo $escape($order['customer_name_snapshot']); ?></td>
                <td><time datetime="<?php echo $escape($order['required_date']); ?>"><?php echo $escape($formatDate($order['required_date'])); ?></time></td>
                <td><?php echo $escape($humanize($order['delivery_method'])); ?></td>
                <td><?php $status = $order['order_status'];
                require VIEW_PATH . '/Components/Admin/status-badge.php'; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>
