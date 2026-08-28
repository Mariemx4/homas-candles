<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Bootstrap/administrator-auth.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$fullName = trim((string) $administrator['first_name'] . ' ' . (string) $administrator['last_name']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Admin dashboard | Hom'As Candles</title>
  <link rel="stylesheet" href="<?php echo BASE_URL . 'assets/css/pages/admin.css?v=' . filemtime(__DIR__ . '/../assets/css/pages/admin.css'); ?>">
</head>
<body class="admin-body">
  <header class="admin-header">
    <a class="admin-brand admin-brand--small" href="<?php echo BASE_URL . 'admin/index.php'; ?>">
      <img src="<?php echo BASE_URL . 'assets/brand/logo.png'; ?>" alt="Hom'As Candles">
      <span>Administration</span>
    </a>
    <form action="<?php echo BASE_URL . 'admin/logout.php'; ?>" method="post">
      <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
      <button class="admin-signout" type="submit">Sign out</button>
    </form>
  </header>
  <main class="admin-dashboard">
    <p class="admin-eyebrow">Dashboard</p>
    <h1>Welcome, <?php echo $escape($fullName); ?></h1>
    <p class="admin-muted">Signed in as <?php echo $escape($administrator['email']); ?></p>

    <section class="admin-dashboard__grid" aria-label="Administration areas">
      <article><h2>Catalogue</h2><p>Product and category management will appear here.</p></article>
      <article><h2>Orders</h2><p>Order processing and payment review will appear here.</p></article>
      <article><h2>Requests</h2><p>Custom product requests will appear here.</p></article>
    </section>
  </main>
</body>
</html>
