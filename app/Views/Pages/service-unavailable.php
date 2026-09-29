<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Page view for Service Unavailable; the controlled page shown when a request cannot be completed.
 *
 * DATA FLOW: App\Http\ErrorBoundary::renderPage() includes this file with
 * $status (503 or 500), $incident (the reference also written to the log),
 * and $contact (app/config/contact.php or null). It deliberately uses no
 * database, session, or shared layout, because any of those may have failed.
 */

if (!isset($status, $incident) || !is_int($status) || !is_string($incident)) {
    throw new RuntimeException('Error page data is required.');
}

$escape = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$isOutage = $status === 503;
// WHAT: the direct channels still work when the website cannot, so a customer
//       can keep ordering by phone or message during an outage.
$whatsApp = is_array($contact ?? null) ? ($contact['channels']['WHATSAPP'] ?? null) : null;
$instagram = is_array($contact ?? null) ? ($contact['channels']['INSTAGRAM'] ?? null) : null;
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title><?php echo $isOutage ? 'Back shortly' : 'Something went wrong'; ?> | Hom'As Candles</title>
  <link rel="stylesheet" href="<?php echo $escape(BASE_URL . 'assets/css/style.css'); ?>">
  <?php // Inline rules keep the page readable even if the stylesheet cannot load. ?>
  <style>
    .service-page { min-height: 100vh; display: grid; place-items: center; padding: 2rem 1rem;
      color: var(--color-ink, #32190d); background: var(--color-page-background, #f8ecdf);
      font-family: var(--font-family-body, Arial, sans-serif); }
    .service-card { width: min(34rem, 100%); padding: clamp(1.5rem, 5vw, 2.75rem); text-align: center;
      background: var(--color-paper, #fffaf4); border: 1px solid var(--color-line, rgba(78, 40, 18, 0.15));
      border-radius: 1.25rem; box-shadow: 0 22px 48px rgba(71, 34, 13, 0.1); }
    .service-card img { width: 4.5rem; height: auto; }
    .service-card h1 { margin: 1rem 0 0.75rem; font-family: var(--font-family-heading, Georgia, serif);
      font-size: clamp(2rem, 7vw, 2.8rem); font-weight: 600; line-height: 1; }
    .service-card p { margin: 0 0 1rem; color: var(--color-text-muted, rgba(50, 25, 13, 0.62)); line-height: 1.6; }
    .service-actions { margin: 1.5rem 0; display: flex; flex-wrap: wrap; justify-content: center; gap: 0.6rem; }
    .service-actions a { padding: 0.7rem 1.1rem; color: var(--color-ink, #32190d); font-size: 0.8rem;
      font-weight: 700; text-decoration: none; border: 1px solid currentColor; border-radius: 99rem; }
    .service-actions a:first-child { color: var(--color-paper, #fffaf4); background: var(--color-ink, #32190d); }
    .service-reference { font-size: 0.75rem; }
    .service-reference code { font-size: 0.85rem; letter-spacing: 0.05em; }
  </style>
</head>

<body>
  <main class="service-page">
    <section class="service-card" aria-labelledby="service-title">
      <img src="<?php echo $escape(BASE_URL . 'assets/brand/logo.png'); ?>" alt="Hom'As Candles">
      <?php if ($isOutage): ?>
        <h1 id="service-title">We’ll be back shortly.</h1>
        <p>Our shop is briefly unavailable while we reconnect. Nothing you were doing has been lost — please try again in a couple of minutes.</p>
      <?php else: ?>
        <h1 id="service-title">Something went wrong.</h1>
        <p>We couldn’t complete this page. Please try again, or reach us directly — we’re happy to help.</p>
      <?php endif; ?>

      <div class="service-actions">
        <?php // An empty href reloads the same address, keeping the product the visitor wanted. ?>
        <a href="">Try again</a>
        <?php if (is_string($whatsApp)): ?>
          <a href="<?php echo $escape($whatsApp); ?>" target="_blank" rel="noopener noreferrer">WhatsApp us</a>
        <?php endif; ?>
        <?php if (is_array($contact ?? null) && is_string($contact['phone_international'] ?? null)): ?>
          <a href="tel:+<?php echo $escape($contact['phone_international']); ?>">Call <?php echo $escape($contact['phone_display'] ?? ''); ?></a>
        <?php endif; ?>
        <?php if (is_string($instagram)): ?>
          <a href="<?php echo $escape($instagram); ?>" target="_blank" rel="noopener noreferrer">Instagram</a>
        <?php endif; ?>
      </div>

      <?php // The reference matches the incident= value in app/storage/logs/application.log. ?>
      <p class="service-reference">If you contact us about this, mention reference <code><?php echo $escape($incident); ?></code>.</p>
    </section>
  </main>
</body>

</html>
