<?php

declare(strict_types=1);

use App\View\Presentation;

$escape = Presentation::escape(...);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?php echo $escape($pageTitle); ?> | Hom'As Candles</title>
  <link rel="stylesheet" href="<?php echo BASE_URL . 'assets/css/pages/admin-print.css?v=' . filemtime(PUBLIC_PATH . '/assets/css/pages/admin-print.css'); ?>">
  <script src="<?php echo BASE_URL . 'assets/js/admin/print.js?v=' . filemtime(PUBLIC_PATH . '/assets/js/admin/print.js'); ?>" defer></script>
</head>
<body class="admin-print-body">
  <?php
  // Print routes must also fail clearly instead of requiring a stale path.
  if (!isset($contentView) || !is_string($contentView) || !is_file($contentView)) {
      error_log('Admin print view is missing.');
      http_response_code(500);
      $contentView = VIEW_PATH . '/Pages/admin/errors/print.php';
  }
require $contentView;
?>
</body>
</html>
