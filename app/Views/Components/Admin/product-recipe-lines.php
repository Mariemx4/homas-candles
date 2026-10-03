<?php declare(strict_types=1); ?>
  <?php foreach ($recipeTypes as $recipeType => $recipeLabel): ?>
    <?php $lines = $recipeType === 'ACTUAL' ? $recipeDetail['actual'] : $recipeDetail['candleEquivalent']; ?>
    <section class="admin-panel admin-panel--table" aria-labelledby="recipe-<?php echo strtolower($recipeType); ?>-title">
      <header class="admin-panel__header"><div><p class="admin-eyebrow"><?php echo $recipeType === 'ACTUAL' ? 'Deducted from stock' : 'Notional, for cost comparison'; ?></p><h2 id="recipe-<?php echo strtolower($recipeType); ?>-title"><?php echo $escape($recipeLabel); ?></h2></div><span class="admin-panel__count"><?php echo $escape(count($lines)); ?></span></header>

      <?php if ($lines === []): ?>
        <?php $emptyTitle = 'No recipe lines yet'; $emptyMessage = 'Add a material below to build this recipe.'; require __DIR__ . '/empty-state.php'; ?>
      <?php else: ?>
        <div class="admin-table-scroll" tabindex="0" aria-label="Scrollable <?php echo strtolower($recipeType); ?> recipe table">
          <table class="admin-table">
            <caption class="admin-visually-hidden"><?php echo $escape($recipeLabel); ?></caption>
            <thead><tr><th scope="col">Material</th><th scope="col">Quantity per unit</th><th scope="col">Unit cost</th><th scope="col">Line cost</th><th scope="col">Actions</th></tr></thead>
            <tbody>
              <?php foreach ($lines as $line): ?>
                <tr>
                  <td><?php echo $escape($line['name']); ?><small><?php echo $escape($line['sku']); ?></small></td>
                  <td>
                    <!-- FORM PURPOSE: Submit inline quantity form with POST; the receiving PHP route must validate the values before using them. -->
                    <form class="admin-inline-quantity-form" method="post" action="<?php echo $escape($recipeActionUrl); ?>">
                      <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
                      <input type="hidden" name="action" value="update_line">
                      <input type="hidden" name="product_id" value="<?php echo $escape($recipeProduct['id']); ?>">
                      <input type="hidden" name="recipe_type" value="<?php echo $escape($recipeType); ?>">
                      <input type="hidden" name="material_id" value="<?php echo $escape($line['material_id']); ?>">
                      <input class="admin-quantity-input" name="quantity_required" aria-label="<?php echo $escape('Quantity per unit for ' . $line['name']); ?>" type="text" inputmode="decimal" maxlength="12" value="<?php echo $escape($line['quantity_required']); ?>">
                      <span><?php echo $escape($line['unit']); ?></span>
                      <button class="admin-button admin-button--secondary" type="submit">Update</button>
                    </form>
                  </td>
                  <td><?php echo $escape(number_format((float) $line['unit_cost'], 3)); ?> TND</td>
                  <td><?php echo $escape(number_format((float) $line['line_cost'], 3)); ?> TND</td>
                  <td>
                    <!-- FORM PURPOSE: Submit submitted values with POST; the receiving PHP route must validate the values before using them. -->
                    <form method="post" action="<?php echo $escape($recipeActionUrl); ?>">
                      <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
                      <input type="hidden" name="action" value="remove_line">
                      <input type="hidden" name="product_id" value="<?php echo $escape($recipeProduct['id']); ?>">
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
        $availableMaterials = array_filter($recipeDetail['materialOptions'], static fn (array $material) => !in_array((string) $material['id'], $usedMaterialIds, true));
      ?>
      <?php if ($availableMaterials !== []): ?>
        <!-- FORM PURPOSE: Submit assign form with POST; the receiving PHP route must validate the values before using them. -->
        <form class="admin-assign-form" method="post" action="<?php echo $escape($recipeActionUrl); ?>">
          <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
          <input type="hidden" name="action" value="add_line">
          <input type="hidden" name="product_id" value="<?php echo $escape($recipeProduct['id']); ?>">
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
