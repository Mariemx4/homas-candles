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

  <?php foreach (['ACTUAL' => 'Actual recipe', 'CANDLE_EQUIVALENT' => 'Candle-equivalent recipe'] as $recipeType => $recipeLabel): ?>
    <?php $lines = $recipeType === 'ACTUAL' ? $detail['actual'] : $detail['candleEquivalent']; ?>
    <section class="admin-panel admin-panel--table" aria-labelledby="recipe-<?php echo strtolower($recipeType); ?>-title">
      <header class="admin-panel__header"><div><p class="admin-eyebrow"><?php echo $recipeType === 'ACTUAL' ? 'Deducted from stock' : 'Notional, for cost comparison'; ?></p><h2 id="recipe-<?php echo strtolower($recipeType); ?>-title"><?php echo $escape($recipeLabel); ?></h2></div><span class="admin-panel__count"><?php echo $escape(count($lines)); ?></span></header>

      <?php if ($lines === []): ?>
        <?php $emptyTitle = 'No recipe lines yet'; $emptyMessage = 'Add a material below to build this recipe.'; require __DIR__ . '/../../Components/Admin/empty-state.php'; ?>
      <?php else: ?>
        <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable <?php echo strtolower($recipeType); ?> recipe table">
          <table class="admin-table">
            <caption class="admin-visually-hidden"><?php echo $escape($recipeLabel); ?></caption>
            <thead><tr><th scope="col">Material</th><th scope="col">Quantity</th><th scope="col">Unit cost</th><th scope="col">Line cost</th><th scope="col">Actions</th></tr></thead>
            <tbody>
              <?php foreach ($lines as $line): ?>
                <tr>
                  <td><?php echo $escape($line['name']); ?><small><?php echo $escape($line['sku']); ?></small></td>
                  <td>
                    <!-- FORM PURPOSE: Submit inline quantity form with POST; the receiving PHP route must validate the values before using them. -->
                    <form class="admin-inline-quantity-form" method="post" action="<?php echo $escape($selfUrl); ?>">
                      <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
                      <input type="hidden" name="action" value="update_line">
                      <input type="hidden" name="product_id" value="<?php echo $escape($product['id']); ?>">
                      <input type="hidden" name="recipe_type" value="<?php echo $escape($recipeType); ?>">
                      <input type="hidden" name="material_id" value="<?php echo $escape($line['material_id']); ?>">
                      <input class="admin-quantity-input" name="quantity_required" type="text" inputmode="decimal" maxlength="12" value="<?php echo $escape($line['quantity_required']); ?>">
                      <span><?php echo $escape($line['unit']); ?></span>
                      <button class="admin-button admin-button--secondary" type="submit">Update</button>
                    </form>
                  </td>
                  <td><?php echo $escape(number_format((float) $line['unit_cost'], 3)); ?> TND</td>
                  <td><?php echo $escape(number_format((float) $line['line_cost'], 3)); ?> TND</td>
                  <td>
                    <!-- FORM PURPOSE: Submit submitted values with POST; the receiving PHP route must validate the values before using them. -->
                    <form method="post" action="<?php echo $escape($selfUrl); ?>">
                      <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
                      <input type="hidden" name="action" value="remove_line">
                      <input type="hidden" name="product_id" value="<?php echo $escape($product['id']); ?>">
                      <input type="hidden" name="recipe_type" value="<?php echo $escape($recipeType); ?>">
                      <input type="hidden" name="material_id" value="<?php echo $escape($line['material_id']); ?>">
                      <button class="admin-button admin-button--secondary" type="submit">Remove</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php
        // COLLECTION IDIOM: Transform every array item and return a new array without changing the original one.
        $usedMaterialIds = array_map(static fn (array $line): string => (string) $line['material_id'], $lines);
        // COLLECTION IDIOM: Keep only array items for which the callback returns true.
        $availableMaterials = array_filter($detail['materialOptions'], static fn (array $material) => !in_array((string) $material['id'], $usedMaterialIds, true));
      ?>
      <?php if ($availableMaterials !== []): ?>
        <!-- FORM PURPOSE: Submit assign form with POST; the receiving PHP route must validate the values before using them. -->
        <form class="admin-assign-form" method="post" action="<?php echo $escape($selfUrl); ?>">
          <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
          <input type="hidden" name="action" value="add_line">
          <input type="hidden" name="product_id" value="<?php echo $escape($product['id']); ?>">
          <input type="hidden" name="recipe_type" value="<?php echo $escape($recipeType); ?>">
          <label class="admin-visually-hidden" for="recipe-material-<?php echo strtolower($recipeType); ?>">Material</label>
          <select id="recipe-material-<?php echo strtolower($recipeType); ?>" name="material_id">
            <?php foreach ($availableMaterials as $material): ?>
              <option value="<?php echo $escape($material['id']); ?>"><?php echo $escape($material['name']); ?> (<?php echo $escape($material['unit']); ?>)</option>
            <?php endforeach; ?>
          </select>
          <label class="admin-visually-hidden" for="recipe-quantity-<?php echo strtolower($recipeType); ?>">Quantity required</label>
          <input id="recipe-quantity-<?php echo strtolower($recipeType); ?>" name="quantity_required" class="admin-quantity-input" type="text" inputmode="decimal" maxlength="12" placeholder="0.000">
          <button class="admin-button" type="submit">Add to recipe</button>
        </form>
      <?php else: ?>
        <p class="admin-panel__intro">Every eligible material is already in this recipe.</p>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
<?php endif; ?>
