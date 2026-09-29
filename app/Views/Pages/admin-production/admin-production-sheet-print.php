<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Page view for Admin Production Sheet Print; it turns prepared application data into HTML.
 *
 * DATA FLOW: An HTTP entry point loads a bootstrap, obtains data from a controller, selects this file as $contentView, and loads a layout. Layout variables such as $escape are therefore injected into this view scope.
 */

$formatDate = static function (mixed $value): string {
    if (!is_string($value) || $value === '') {
        return 'Not set';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable ? $date->format('d M Y') : 'Not set';
};
$formatQuantity = static fn (mixed $quantity, mixed $unit): string => number_format((float) $quantity, 3) . ' ' . (string) $unit;
$describeGroup = static function (array $group): string {
    // COLLECTION IDIOM: Keep only array items for which the callback returns true.
    $parts = array_filter([$group['color_name'], $group['scent_name']]);
    return $parts === [] ? '—' : implode(' · ', $parts);
};
?>
<main class="admin-print-page">
  <?php if (is_string($productionSheet['loadError'])): ?>
    <!-- SECTION PURPOSE: Group the print error content, states, and controls as one accessible page region. -->
    <section class="admin-print-error"><h1>Preparation sheet unavailable</h1><p><?php echo $escape($productionSheet['loadError']); ?></p><a href="<?php echo BASE_URL . 'admin/production/sheet.php'; ?>">Back to preparation sheet</a></section>
  <?php else: ?>
    <header class="admin-print-header">
      <div><img src="<?php echo BASE_URL . 'assets/brand/logo.png'; ?>" alt="Hom'As Candles"><span>Preparation sheet</span></div>
      <button class="admin-print-button" type="button">Print</button>
    </header>
    <!-- SECTION PURPOSE: Group the print title content, states, and controls as one accessible page region. -->
    <section class="admin-print-title">
      <div><p>Schedule window</p><h1><?php echo $escape($formatDate($productionSheet['dateFrom'])); ?> – <?php echo $escape($formatDate($productionSheet['dateTo'])); ?></h1></div>
      <dl><div><dt>Orders</dt><dd><?php echo $escape($productionSheet['orderCount']); ?></dd></div><div><dt>Missing recipes</dt><dd><?php echo $escape(count($productionSheet['missingRecipeProducts'])); ?></dd></div><div><dt>Shortages</dt><dd><?php echo $escape(count($productionSheet['shortages'])); ?></dd></div></dl>
    </section>

    <?php if ($productionSheet['missingRecipeProducts'] !== []): ?>
      <!-- SECTION PURPOSE: Group the print section content, states, and controls as one accessible page region. -->
      <section class="admin-print-section"><h2>Missing recipe data</h2>
        <?php // COLLECTION IDIOM: array_map extracts each missing product name. ?>
        <p><?php echo $escape(implode(', ', array_map(static fn (array $p): string => (string) $p['name'], $productionSheet['missingRecipeProducts']))); ?> cannot be checked for material feasibility.</p>
      </section>
    <?php endif; ?>

    <!-- SECTION PURPOSE: Group the print section content, states, and controls as one accessible page region. -->
    <section class="admin-print-section"><h2>Products to produce</h2>
      <?php if ($productionSheet['toProduce'] === []): ?><p>Nothing to produce for this window.</p><?php else: ?>
        <table><thead><tr><th>Product</th><th>Color / scent</th><th>Customization</th><th>Qty</th></tr></thead><tbody>
        <?php foreach ($productionSheet['toProduce'] as $group): ?>
          <tr><td><?php echo $escape($group['product_name']); ?></td><td><?php echo $escape($describeGroup($group)); ?></td><td><?php echo $escape($group['customization_notes'] ?? '—'); ?></td><td><?php echo $escape($group['quantity']); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>
    </section>

    <!-- SECTION PURPOSE: Group the print section content, states, and controls as one accessible page region. -->
    <section class="admin-print-section"><h2>Sets to assemble</h2>
      <?php if ($productionSheet['toAssemble'] === []): ?><p>No sets to assemble for this window.</p><?php else: ?>
        <table><thead><tr><th>Set</th><th>Color / scent</th><th>Customization</th><th>Qty</th></tr></thead><tbody>
        <?php foreach ($productionSheet['toAssemble'] as $group): ?>
          <tr><td><?php echo $escape($group['product_name']); ?></td><td><?php echo $escape($describeGroup($group)); ?></td><td><?php echo $escape($group['customization_notes'] ?? '—'); ?></td><td><?php echo $escape($group['quantity']); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>
    </section>

    <!-- SECTION PURPOSE: Group the print section content, states, and controls as one accessible page region. -->
    <section class="admin-print-section"><h2>Material feasibility</h2>
      <?php if ($productionSheet['materials'] === []): ?><p>No tracked, consumable materials are required for this window.</p><?php else: ?>
        <table><thead><tr><th>Material</th><th>Required</th><th>In stock</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($productionSheet['materials'] as $material): ?>
          <tr><td><?php echo $escape($material['name']); ?></td><td><?php echo $escape($formatQuantity($material['required_quantity'], $material['unit'])); ?></td><td><?php echo $escape($formatQuantity($material['stock_quantity'], $material['unit'])); ?></td><td><?php echo $material['shortfall'] !== null ? 'Short by ' . $escape($formatQuantity($material['shortfall'], $material['unit'])) : 'Available'; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>
    </section>

    <footer class="admin-print-footer">Internal preparation copy · Order numbers only. Customer contact details, private notes, and payment data intentionally omitted.</footer>
  <?php endif; ?>
</main>
