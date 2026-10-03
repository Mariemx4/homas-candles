<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Page view for Admin Inventory Recipe; it turns prepared application data into HTML.
 *
 * DATA FLOW: An HTTP entry point loads a bootstrap, obtains data from a controller, selects this file as $contentView, and loads a layout. Layout variables such as $escape are therefore injected into this view scope.
 */

use App\Services\ProductRecipeService;

$formatMoney = static fn (mixed $amount): string => $amount === null ? 'Not set' : number_format((float) $amount, 3) . ' TND';
$recipeNotice ??= ['errors' => [], 'values' => [], 'success' => null];
$recipeForm ??= ['errors' => [], 'values' => [], 'success' => null];
$errors = $recipeForm['errors'];
$selfUrl = BASE_URL . 'admin/inventory/recipe.php';
$detail = $recipeShow['detail'];
?>
<?php if (!is_array($detail)): ?>
  <section class="admin-panel admin-detail-error" aria-labelledby="recipe-error-title">
    <div class="admin-empty-state" role="alert">
      <span class="admin-empty-state__mark admin-empty-state__mark--error" aria-hidden="true">!</span>
      <h1 id="recipe-error-title"><?php echo (int) $recipeShow['httpStatus'] === 404 ? 'Product not found' : 'Unable to show recipe'; ?></h1>
      <p><?php echo $escape($recipeShow['loadError']); ?></p>
      <a class="admin-button admin-button--secondary" href="<?php echo BASE_URL . 'admin/catalogue/products.php'; ?>">Back to products</a>
    </div>
  </section>
<?php else: ?>
  <?php $product = $detail['product']; ?>
  <header class="admin-order-header">
    <div>
      <?php // URL IDIOM: http_build_query safely encodes each query-string value. ?>
      <a class="admin-back-link" href="<?php echo $escape(BASE_URL . 'admin/catalogue/products.php?' . http_build_query(['id' => $product['id']])); ?>">← Back to product</a>
      <p class="admin-eyebrow">Recipe editor</p>
      <h1><?php echo $escape($product['name']); ?></h1>
    </div>
  </header>

  <?php if (is_string($recipeNotice['success'])): ?>
    <?php $alertTone = 'success'; $alertTitle = 'Saved'; $alertMessage = $recipeNotice['success']; require __DIR__ . '/../../Components/Admin/alert.php'; ?>
  <?php endif; ?>
  <?php if (isset($errors['form']) || isset($errors['recipe'])): ?>
    <?php $alertTone = 'error'; $alertTitle = 'Not saved'; $alertMessage = $errors['form'] ?? $errors['recipe']; require __DIR__ . '/../../Components/Admin/alert.php'; ?>
  <?php endif; ?>

  <section class="admin-panel admin-detail-card" aria-labelledby="cost-summary-title">
    <header class="admin-panel__header"><h2 id="cost-summary-title">Cost summary</h2></header>
    <?php $pricing = $detail['pricing'] ?? null; ?>
    <dl class="admin-money-list">
      <div><dt>Actual recipe cost</dt><dd><?php echo $escape($formatMoney($detail['actualCost'])); ?></dd></div>
      <?php // Sets add their components' costs to their own recipe, so show the full real cost too. ?>
      <?php if ($detail['realCost'] !== $detail['actualCost']): ?>
        <div><dt>Total real cost (with components)</dt><dd><?php echo $escape($formatMoney($detail['realCost'])); ?></dd></div>
      <?php endif; ?>
      <div><dt>Candle-equivalent cost</dt><dd><?php echo $escape($formatMoney($detail['candleEquivalentCost'])); ?></dd></div>
      <div><dt>Selling price</dt><dd><?php echo $escape($formatMoney($product['selling_price'])); ?></dd></div>
      <div class="admin-money-list__balance"><dt>Net profit</dt><dd><?php echo $escape($formatMoney($detail['profit'])); ?></dd></div>
    </dl>
    <?php // WHAT: how PricingService reached this price, or what is still missing. ?>
    <?php if (is_array($pricing) && $pricing['rule'] !== null): ?>
      <p class="admin-panel__intro"><strong><?php echo $escape($pricing['rule_label']); ?>.</strong> <?php echo $escape($pricing['explanation']); ?>.</p>
      <?php foreach ($pricing['event_favor_tiers'] as $tier): ?>
        <p class="admin-panel__intro"><?php echo $escape($tier['label']); ?> favors: <?php echo $escape($formatMoney(\App\Services\PricingService::formatMoney($tier['unit_price_millis']))); ?> each (cost × <?php echo $escape($tier['multiplier']); ?>)</p>
      <?php endforeach; ?>
    <?php elseif (is_array($pricing)): ?>
      <?php $alertTone = 'error'; $alertTitle = 'Price not calculated'; $alertMessage = (string) $pricing['issue']; require __DIR__ . '/../../Components/Admin/alert.php'; ?>
    <?php endif; ?>
    <p class="admin-panel__intro">Every recipe or material-cost change recalculates current catalogue prices automatically. Placed orders keep the prices frozen on them.</p>
  </section>

  <?php $recipeDetail = $detail; $recipeProduct = $product; $recipeActionUrl = $selfUrl;
    $recipeTypes = ['ACTUAL' => 'Actual recipe', 'CANDLE_EQUIVALENT' => 'Candle-equivalent recipe'];
    require __DIR__ . '/../../Components/Admin/product-recipe-lines.php'; ?>
<?php endif; ?>
