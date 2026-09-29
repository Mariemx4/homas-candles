<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Reusable view fragment for Navigation.
 *
 * DATA FLOW: A parent view prepares the variables this fragment expects and requires it in the same PHP scope. This is a partial, not a page that should be opened directly.
 */

$activeNavigation ??= 'dashboard';
$adminNavigation = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'short' => 'DB', 'href' => BASE_URL . 'admin/index.php'],
    ['key' => 'orders', 'label' => 'Orders', 'short' => 'OR', 'href' => BASE_URL . 'admin/orders/index.php'],
    ['key' => 'requests', 'label' => 'Custom Requests', 'short' => 'CR', 'href' => BASE_URL . 'admin/requests/index.php'],
    ['key' => 'payments', 'label' => 'Payments', 'short' => 'PY', 'href' => BASE_URL . 'admin/payments/index.php'],
    ['key' => 'catalogue', 'label' => 'Catalogue', 'short' => 'CA', 'href' => BASE_URL . 'admin/catalogue/products.php'],
    ['key' => 'inventory', 'label' => 'Inventory', 'short' => 'IN', 'href' => BASE_URL . 'admin/inventory/materials.php'],
    ['key' => 'production', 'label' => 'Production', 'short' => 'PR', 'href' => BASE_URL . 'admin/production/queue.php'],
    ['key' => 'customers', 'label' => 'Customers', 'short' => 'CU', 'href' => BASE_URL . 'admin/customers/index.php'],
    ['key' => 'reports', 'label' => 'Reports', 'short' => 'RE', 'href' => BASE_URL . 'admin/reports/index.php'],
    ['key' => 'settings', 'label' => 'Settings', 'short' => 'SE', 'href' => BASE_URL . 'admin/settings/index.php'],
];
?>
<aside class="admin-sidebar" id="admin-navigation" aria-label="Administrator navigation">
  <div class="admin-sidebar__brand-row">
    <a class="admin-brand admin-brand--shell" href="<?php echo BASE_URL . 'admin/index.php'; ?>">
      <img src="<?php echo BASE_URL . 'assets/brand/logo.png'; ?>" alt="Hom'As Candles">
      <span>Administration</span>
    </a>
    <button class="admin-nav-close" type="button" aria-label="Close navigation">&times;</button>
  </div>

  <nav class="admin-nav" aria-label="Main">
    <p class="admin-nav__label">Workspace</p>
    <ul>
      <?php foreach ($adminNavigation as $item): ?>
        <li>
          <?php if (isset($item['href'])): ?>
            <a class="admin-nav__item<?php echo ($item['key'] ?? '') === $activeNavigation ? ' admin-nav__item--current' : ''; ?>"
               href="<?php echo $escape($item['href']); ?>"<?php echo ($item['key'] ?? '') === $activeNavigation ? ' aria-current="page"' : ''; ?>>
              <span class="admin-nav__icon" aria-hidden="true"><?php echo $escape($item['short']); ?></span>
              <span><?php echo $escape($item['label']); ?></span>
            </a>
          <?php else: ?>
            <span class="admin-nav__item admin-nav__item--disabled" aria-disabled="true">
              <span class="admin-nav__icon" aria-hidden="true"><?php echo $escape($item['short']); ?></span>
              <span><?php echo $escape($item['label']); ?></span>
              <span class="admin-nav__soon">Coming soon</span>
            </span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </nav>

  <div class="admin-sidebar__footer">
    <a class="admin-storefront-link" href="<?php echo $escape(BASE_URL . 'index.php'); ?>">
      <span>View storefront</span>
      <span aria-hidden="true">↗</span>
    </a>
    <div class="admin-sidebar__identity">
      <span class="admin-identity__avatar" aria-hidden="true"><?php echo $escape($administratorInitials); ?></span>
      <span><strong><?php echo $escape($administratorName); ?></strong><small>Active administrator</small></span>
    </div>
    <!-- FORM PURPOSE: Submit submitted values with POST; the receiving PHP route must validate the values before using them. -->
    <form action="<?php echo BASE_URL . 'admin/logout.php'; ?>" method="post">
      <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
      <button class="admin-signout" type="submit">Sign out</button>
    </form>
  </div>
</aside>
