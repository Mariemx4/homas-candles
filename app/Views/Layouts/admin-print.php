<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Outer HTML layout for Admin Print; it provides the shared page shell and view helpers.
 *
 * DATA FLOW: The entry point prepares $contentView and page data, then requires this layout. Variables defined here, including $escape, are in scope when the layout requires the selected page view.
 */

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?php echo $escape($pageTitle); ?> | Hom'As Candles</title>
  <link rel="stylesheet" href="<?php echo BASE_URL . 'assets/css/pages/admin-print.css?v=' . filemtime(PUBLIC_PATH . '/assets/css/pages/admin-print.css'); ?>">
  <script src="<?php echo BASE_URL . 'assets/js/admin-print.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin-print.js'); ?>" defer></script>
</head>
<body class="admin-print-body">
  <?php
  // Print routes must also fail clearly instead of requiring a stale path.
  if (!isset($contentView) || !is_string($contentView) || !is_file($contentView)) {
      error_log('Admin print view is missing.');
      http_response_code(500);
      $contentView = __DIR__ . '/../Pages/admin-print-error.php';
  }
  require $contentView;
  ?>
</body>
</html>
