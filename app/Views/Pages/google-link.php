<?php

declare(strict_types=1);

use App\View\Presentation;

$escape = Presentation::escape(...);
require VIEW_PATH . '/Layouts/header.php';
?>
<main id="main-content" tabindex="-1" class="signup-page google-link-page">
  <section class="google-link-card" aria-labelledby="google-link-title">
    <p class="signup-kicker"><?php echo site_text('nav.your_space'); ?></p>
    <h1 id="google-link-title"><?php echo site_text('google.link_title'); ?></h1>
    <p><?php echo site_text('google.link_intro'); ?></p>
    <div class="google-verified"><span aria-hidden="true">✓</span><div><strong><?php echo site_text('google.verified'); ?></strong><span><?php echo $escape($identity->email); ?></span></div></div>
    <form method="post" action="<?php echo $escape(BASE_URL . 'auth/google-link.php'); ?>">
      <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
      <input type="hidden" name="confirm_link" value="1">
      <button class="signup-submit" type="submit"><?php echo site_text('google.confirm_link'); ?></button>
    </form>
    <p><a href="<?php echo $escape(BASE_URL . 'account.php'); ?>"><?php echo site_text('google.not_now'); ?></a></p>
  </section>
</main>
<?php require VIEW_PATH . '/Layouts/footer.php'; ?>
