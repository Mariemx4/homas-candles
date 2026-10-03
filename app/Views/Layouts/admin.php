<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Outer HTML layout for Admin; it provides the shared page shell and view helpers.
 *
 * DATA FLOW: The entry point prepares $contentView and page data, then requires this layout. Variables defined here, including $escape, are in scope when the layout requires the selected page view.
 */

// Convert dynamic values to safe HTML text. The page view inherits this closure
// because it is required later from this same PHP variable scope.
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

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
  <title><?php echo $escape($pageTitle); ?> | Hom'As Candles</title>
  <link rel="stylesheet" href="<?php echo BASE_URL . 'assets/css/pages/admin.css?v=' . filemtime(PUBLIC_PATH . '/assets/css/pages/admin.css'); ?>">
  <script src="<?php echo BASE_URL . 'assets/js/admin-navigation.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin-navigation.js'); ?>" defer></script>
  <script type="module" src="<?php echo BASE_URL . 'assets/js/admin-selects.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin-selects.js'); ?>"></script>
  <script type="module" src="<?php echo BASE_URL . 'assets/js/icons.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/icons.js'); ?>"></script>
</head>

<body class="admin-body">
  <!-- Keyboard users can bypass repeated navigation and jump to the page. -->
  <a class="admin-skip-link" href="#admin-main">Skip to admin content</a>
  <div class="admin-shell">
    <?php // Shared sidebar component inherits administrator/layout variables. 
    ?>
    <?php require __DIR__ . '/../Components/Admin/navigation.php'; ?>

    <div class="admin-shell__workspace">
      <header class="admin-topbar">
        <button class="admin-menu-button" type="button" aria-controls="admin-navigation" aria-expanded="false">
          <span class="admin-menu-button__lines" aria-hidden="true"></span>
        </button>
        <div class="admin-topbar__context">
          <span class="admin-topbar__eyebrow">Hom'As Candles</span>
          <span class="admin-page-name"><?php echo $escape($pageTitle); ?></span>
        </div>
        <div class="admin-identity">
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
        $contentView ??= __DIR__ . '/../Pages/admin-dashboard.php';

        // Never require an arbitrary missing path. Showing an explicit error is
        // safer than silently rendering Dashboard, which would hide route bugs.
        if (!is_file($contentView)) {
          error_log('Admin page view is missing.');
          http_response_code(500);
          $contentView = __DIR__ . '/../Pages/admin-view-error.php';
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
</body>

</html>
