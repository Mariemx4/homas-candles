<?php

declare(strict_types=1);

use App\View\Presentation;
use App\View\AdminIcons;

// Convert dynamic values to safe HTML text. The page view inherits this closure
// because it is required later from this same PHP variable scope.
header('Cache-Control: no-store, private');
$escape = Presentation::escape(...);

// Build a display name from the authenticated account. `?? ''` supplies an
// empty fallback when a database field is absent, and trim removes spare spaces.
$administratorName = trim((string) ($administrator['first_name'] ?? '') . ' ' . (string) ($administrator['last_name'] ?? ''));
$administratorName = $administratorName !== '' ? $administratorName : 'Administrator';

// The avatar uses the first character of each name. Final fallbacks guarantee
// that the interface never displays a blank avatar.
$administratorInitials = strtoupper(
    substr((string) ($administrator['first_name'] ?? 'A'), 0, 1)
    . substr((string) ($administrator['last_name'] ?? ''), 0, 1)
);
$administratorInitials = $administratorInitials !== '' ? $administratorInitials : 'A';

?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&amp;family=Inter:wght@400;500;600&amp;display=swap" rel="stylesheet">
  <title><?php echo $escape($pageTitle); ?> | Hom'As Candles</title>
  <?php foreach (\App\Services\AdminAssets::styles(PUBLIC_PATH) as $stylesheet): ?><link rel="stylesheet" href="<?php echo $escape(BASE_URL . $stylesheet); ?>"><?php endforeach; ?>
  <script src="<?php echo BASE_URL . 'assets/js/admin/navigation.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin/navigation.js'); ?>" defer></script>
  <script src="<?php echo BASE_URL . 'assets/js/admin/confirmation.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin/confirmation.js'); ?>" defer></script>
  <script src="<?php echo BASE_URL . 'assets/js/admin/workspace.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin/workspace.js'); ?>" defer></script>
  <script src="<?php echo BASE_URL . 'assets/js/admin/responsive.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin/responsive.js'); ?>" defer></script>
  <script type="module" src="<?php echo BASE_URL . 'assets/js/admin/selects.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin/selects.js') . '-' . filemtime(PUBLIC_PATH . '/assets/js/custom_select.js'); ?>"></script>
</head>

<body class="admin-body">
  <!-- Cloned by workspace enhancements; the same registry renders PHP actions. -->
  <template id="admin-icon-library">
    <?php foreach (['eye', 'edit', 'photo', 'settings', 'payments', 'production', 'history', 'language', 'products', 'requests', 'customers', 'search', 'filter', 'back', 'arrow', 'save', 'stock', 'check'] as $iconName): ?>
      <span data-admin-icon="<?php echo $iconName; ?>"><?php echo AdminIcons::render($iconName); ?></span>
    <?php endforeach; ?>
  </template>
  <!-- Keyboard users can bypass repeated navigation and jump to the page. -->
  <a class="admin-skip-link" href="#admin-main">Skip to admin content</a>
  <div class="admin-shell">
    <?php // Shared sidebar component inherits administrator/layout variables.
    ?>
    <?php require VIEW_PATH . '/Components/Admin/navigation.php'; ?>

    <div class="admin-shell__workspace">
      <header class="admin-topbar">
        <button class="admin-menu-button" type="button" aria-controls="admin-navigation" aria-expanded="false">
          <span class="admin-menu-button__lines" aria-hidden="true"></span>
          <span class="admin-menu-button__label admin-visually-hidden">Open navigation</span>
        </button>
        <div class="admin-topbar__context">
          <span class="admin-topbar__eyebrow">Hom'As Candles</span>
          <span class="admin-page-name"><?php echo $escape($pageTitle); ?></span>
        </div>
        <div class="admin-identity">
          <details class="admin-create-menu"><summary><?php echo AdminIcons::render('plus'); ?><span>New</span></summary><nav aria-label="Create"><a href="<?php echo BASE_URL; ?>admin/orders/create.php"><?php echo AdminIcons::render('orders'); ?>New order</a><a href="<?php echo BASE_URL; ?>admin/requests/create.php"><?php echo AdminIcons::render('requests'); ?>Custom request</a><a href="<?php echo BASE_URL; ?>admin/catalogue/products.php?id=new"><?php echo AdminIcons::render('products'); ?>Product draft</a><a href="<?php echo BASE_URL; ?>admin/inventory/materials.php?id=new"><?php echo AdminIcons::render('stock'); ?>Material</a></nav></details>
          <span class="admin-identity__avatar" aria-hidden="true"><?php echo $escape($administratorInitials); ?></span>
          <span class="admin-identity__copy">
            <strong><?php echo $escape($administratorName); ?></strong>
            <span>Administrator</span>
          </span>
        </div>
      </header>


      <main class="admin-main" id="admin-main" tabindex="-1">
        <?php
        // `??=` assigns a fallback only if the entry point did not provide one.
        $breadcrumbs ??= [['label' => 'Administration'], ['label' => $pageTitle ?? 'Dashboard']];
$contentView ??= VIEW_PATH . '/Pages/admin/dashboard/index.php';

// Never require an arbitrary missing path. Showing an explicit error is
// safer than silently rendering Dashboard, which would hide route bugs.
if (!is_file($contentView)) {
    error_log('Admin page view is missing.');
    http_response_code(500);
    $contentView = VIEW_PATH . '/Pages/admin/errors/view.php';
}
?>
        <nav class="admin-breadcrumbs" aria-label="Breadcrumb">
          <ol>
            <?php foreach ($breadcrumbs as $index => $breadcrumb): ?>
              <li<?php echo $index === array_key_last($breadcrumbs) ? ' aria-current="page"' : ''; ?>>
                <?php if (isset($breadcrumb['href']) && $index !== array_key_last($breadcrumbs)): ?>
                  <a href="<?php echo $escape($breadcrumb['href']); ?>"><?php echo $escape($breadcrumb['label']); ?></a>
                <?php else: ?>
                  <?php echo $escape($breadcrumb['label']); ?>
                <?php endif; ?>
                </li>
              <?php endforeach; ?>
          </ol>
        </nav>
        <?php
// This is the important scope relationship: the required page can use
// `$escape`, `$administrator`, and other variables currently available.
require $contentView;
?>
      </main>

    </div>
  </div>
  <button class="admin-nav-backdrop" type="button" tabindex="-1" aria-label="Close navigation"></button>
  <?php require VIEW_PATH . '/Components/Admin/confirmation-dialog.php'; ?>
</body>

</html>
