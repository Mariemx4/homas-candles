<?php

declare(strict_types=1);

use App\Services\Locale;
use App\View\Presentation;

$escape = Presentation::escape(...);
$errorText = static function (string $field, string $message): string {
    if (str_starts_with($message, 'google.')) {
        return Locale::text($message);
    }
    return Locale::text('google.error_' . $field);
};
$fields = [
    'first_name' => ['signup.first_name', 'text', 100, 'given-name'],
    'last_name' => ['signup.last_name', 'text', 100, 'family-name'],
    'phone_number' => ['signup.primary_phone', 'tel', 20, 'tel'],
    'address_label' => ['signup.address_label', 'text', 50, 'off'],
    'governorate' => ['checkout.governorate', 'select', 100, 'address-level1'],
    'street_address' => ['signup.street', 'textarea', 255, 'street-address'],
    'delegation' => ['checkout.delegation', 'text', 100, 'address-level2'],
    'postal_code' => ['checkout.postal', 'text', 4, 'postal-code'],
];
require VIEW_PATH . '/Layouts/header.php';
?>
<main id="main-content" tabindex="-1" class="signup-page">
  <section class="signup-shell" aria-labelledby="google-complete-title">
    <div class="signup-story" aria-hidden="true">
      <img src="<?php echo $escape(BASE_URL . 'assets/brand/candle.jpg'); ?>" alt="" class="signup-story__image">
      <div class="signup-story__overlay"></div>
      <div class="signup-story__content"><span class="signup-story__eyebrow"><?php echo site_text('signup.eyebrow'); ?></span><blockquote><?php echo site_text('signup.quote_1'); ?><br><?php echo site_text('signup.quote_2'); ?></blockquote><p><?php echo site_text('signup.story'); ?></p></div>
    </div>
    <div class="signup-panel"><div class="signup-panel__inner">
      <p class="signup-kicker"><?php echo site_text('google.step_two'); ?></p>
      <h1 id="google-complete-title"><?php echo site_text('google.complete_title'); ?></h1>
      <p class="signup-intro"><?php echo site_text('google.complete_intro'); ?></p>
      <div class="google-verified"><span aria-hidden="true">✓</span><div><strong><?php echo site_text('google.verified'); ?></strong><span><?php echo $escape($identity->email); ?></span></div></div>
      <?php if ($errors !== []): ?>
        <div class="auth-error-summary" role="alert" tabindex="-1" data-error-summary><h2><?php echo site_text('login.check'); ?></h2><ul>
          <?php foreach ($errors as $key => $message): ?><li><a href="#<?php echo $escape(isset($fields[$key]) ? $key : 'google-complete-form'); ?>"><?php echo $escape($errorText($key, $message)); ?></a></li><?php endforeach; ?>
        </ul></div>
      <?php endif; ?>
      <form method="post" action="<?php echo $escape(BASE_URL . 'auth/google-complete.php'); ?>" id="google-complete-form" class="signup-form" data-auth-form data-validation-error="<?php echo site_text('contact.validation_error'); ?>" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
        <?php foreach ($fields as $key => [$label, $type, $limit, $autocomplete]):
            $invalid = isset($errors[$key]);
            $attributes = $invalid ? ' aria-invalid="true" aria-describedby="' . $key . '-validation-message"' : '';
            $required = $key !== 'address_label';
            ?>
          <?php if ($key === 'address_label'): ?><div class="signup-form__section signup-field--wide"><h2><?php echo site_text('signup.address_title'); ?></h2><p><?php echo site_text('signup.address_intro'); ?></p></div><?php endif; ?>
          <div class="signup-field<?php echo in_array($key, ['phone_number', 'street_address'], true) ? ' signup-field--wide' : ''; ?>">
            <label for="<?php echo $key; ?>"><?php echo site_text($label); ?><?php if (!$required): ?> <span>(<?php echo site_text('checkout.optional'); ?>)</span><?php endif; ?></label>
            <?php if ($type === 'select'): ?>
              <select id="<?php echo $key; ?>" name="<?php echo $key; ?>" autocomplete="<?php echo $autocomplete; ?>" required<?php echo $attributes; ?>><option value=""><?php echo site_text('checkout.choose_governorate'); ?></option><?php foreach ($governorates as $governorate): ?><option value="<?php echo $escape($governorate); ?>"<?php echo ($values[$key] ?? '') === $governorate ? ' selected' : ''; ?>><?php echo $escape($governorate); ?></option><?php endforeach; ?></select>
            <?php elseif ($type === 'textarea'): ?>
              <textarea id="<?php echo $key; ?>" name="<?php echo $key; ?>" rows="3" autocomplete="<?php echo $autocomplete; ?>" maxlength="<?php echo $limit; ?>" required<?php echo $attributes; ?>><?php echo $escape($values[$key] ?? ''); ?></textarea>
            <?php else: ?>
              <input id="<?php echo $key; ?>" name="<?php echo $key; ?>" type="<?php echo $type; ?>" autocomplete="<?php echo $autocomplete; ?>" maxlength="<?php echo $limit; ?>" value="<?php echo $escape($values[$key] ?? ''); ?>"<?php echo $required ? ' required' : ''; ?><?php echo $key === 'phone_number' ? ' inputmode="tel" pattern="(?:\+216|00216)?[\s\-]*(?:\d[\s\-]*){8}"' : ''; ?><?php echo $key === 'postal_code' ? ' inputmode="numeric" pattern="[0-9]{4}"' : ''; ?><?php echo $attributes; ?>>
            <?php endif; ?>
            <?php if ($invalid): ?><p id="<?php echo $key; ?>-validation-message" class="field-validation-message" role="alert"><?php echo $escape($errorText($key, $errors[$key])); ?></p><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <button class="signup-submit" type="submit"><?php echo site_text('google.finish'); ?></button>
      </form>
      <div class="ui-state-group ui-state-group--compact auth-state-group" data-auth-states data-initial-state="empty" aria-live="polite">
        <div class="ui-state" data-ui-state="loading" hidden><span class="ui-state__spinner" aria-hidden="true"></span><p><?php echo site_text('google.finishing'); ?></p></div>
        <div class="ui-state ui-state--error" data-ui-state="error" hidden><p data-ui-state-message></p></div>
      </div>
      <p class="signup-login"><a href="<?php echo $escape(BASE_URL . 'login.php'); ?>"><?php echo site_text('google.back_login'); ?></a></p>
      <p class="signup-note"><?php echo site_text('signup.private'); ?></p>
    </div></div>
  </section>
</main>
<?php require VIEW_PATH . '/Layouts/footer.php'; ?>
