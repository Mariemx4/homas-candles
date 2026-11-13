<?php

declare(strict_types=1);

use App\View\Presentation;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        require_once __DIR__ . '/../app/Bootstrap/customer-auth.php';
        $customerAuthController->signup($_POST);
    } catch (Throwable) {
        // WHAT: safely recover when authentication dependencies (usually the
        //       database) are unavailable before the controller is constructed.
        // WHY:  customers should see the styled form, never a PHP stack trace,
        //       database details, or internal file paths.
        error_log('Customer signup bootstrap failed.');
        require_once __DIR__ . '/../app/Bootstrap/session.php';

        $safeValues = [];
        foreach ([
            'first_name', 'last_name', 'email', 'phone_number',
            'second_phone_number', 'address_label', 'street_address',
            'governorate', 'delegation', 'postal_code',
        ] as $safeKey) {
            $safeValues[$safeKey] = isset($_POST[$safeKey]) && is_string($_POST[$safeKey])
                ? $_POST[$safeKey]
                : '';
        }

        (new \App\Auth\AuthFormFlash())->put(
            'signup',
            ['form' => 'Account creation is temporarily unavailable. Please try again.'],
            $safeValues
        );
        header('Location: ' . BASE_URL . 'signup.php', true, 303);
        exit;
    }
}
require_once __DIR__ . '/../app/Bootstrap/session.php';

use App\Auth\AuthFormFlash;

$governorates = require __DIR__ . '/../app/config/governorates.php';
$formState = (new AuthFormFlash())->consume('signup');
$errors = $formState['errors'];
$values = $formState['values'];
$authInitialState = $errors !== [] ? 'error' : 'empty';
$stateMessage = $errors['form'] ?? \App\Services\Locale::text('contact.validation_error');
$pageTitle = \App\Services\Locale::text('nav.create_account') . " | Hom'As Candles";
$escape = Presentation::escape(...);
$fieldError = static fn (string $field): ?string => isset($errors[$field]) && is_string($errors[$field]) ? $errors[$field] : null;

// WHAT: name exactly which unique signup details are already registered.
// WHY:  the conflict may involve one, two, or all three fields, so the summary
//       must describe the actual combination instead of always staying generic.
$duplicateFieldLabels = [
    'email' => 'email address',
    'phone_number' => 'primary phone number',
    'second_phone_number' => 'secondary phone number',
];
$usedLabels = [];
foreach ($duplicateFieldLabels as $field => $label) {
    if (isset($errors[$field]) && str_contains((string) $errors[$field], 'already used')) {
        $usedLabels[] = $label;
    }
}
$joinLabels = static function (array $labels): string {
    if (count($labels) < 2) {
        return $labels[0] ?? '';
    }
    if (count($labels) === 2) {
        return $labels[0] . ' and ' . $labels[1];
    }

    $lastLabel = array_pop($labels);
    return implode(', ', $labels) . ', and ' . $lastLabel;
};
$duplicateSummary = $usedLabels === []
    ? null
    : ucfirst($joinLabels($usedLabels)) . (count($usedLabels) === 1
        ? ' is already used.'
        : ' are already used.');
$showErrorSummary = $duplicateSummary !== null || count($errors) > 1;
$stateMessage = $errors['form'] ?? $duplicateSummary ?? $stateMessage;

require_once VIEW_PATH . '/Layouts/header.php';
?>

<main id="main-content" tabindex="-1" class="signup-page">
  <section class="signup-shell" aria-labelledby="signup-title">
    <div class="signup-story" aria-hidden="true">
      <img src="<?php echo BASE_URL . 'assets/brand/candle.jpg'; ?>" alt="" class="signup-story__image">
      <div class="signup-story__overlay"></div>
      <div class="signup-story__content">
        <span class="signup-story__eyebrow"><?php echo site_text('signup.eyebrow'); ?></span>
        <blockquote><?php echo site_text('signup.quote_1'); ?><br><?php echo site_text('signup.quote_2'); ?></blockquote>
        <p><?php echo site_text('signup.story'); ?></p>
      </div>
    </div>

    <div class="signup-panel">
      <div class="signup-panel__inner">
        <p class="signup-kicker"><?php echo site_text('signup.join'); ?></p>
        <h1 id="signup-title"><?php echo site_text('signup.title'); ?></h1>
        <p class="signup-intro"><?php echo site_text('signup.intro'); ?></p>

        <?php require VIEW_PATH . '/Components/google-sign-in.php'; ?>

        <?php if ($showErrorSummary): ?>
          <!-- The summary complements (not replaces) the error beside each field. -->
          <div class="auth-error-summary" role="alert" tabindex="-1" data-error-summary>
            <h2><?php echo $escape($duplicateSummary ?? \App\Services\Locale::text('login.check')); ?></h2>
            <ul>
              <?php foreach ($errors as $field => $message): ?>
                <li><a href="#<?php echo $escape($field === 'form' ? 'first_name' : $field); ?>">
                    <?php echo $escape($message); ?>
                  </a></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <form class="signup-form" action="<?php echo BASE_URL . 'signup.php'; ?>" method="POST" data-auth-form
          data-validation-error="<?php echo site_text('contact.validation_error'); ?>" novalidate>
          <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">

          <?php
          // WHAT: these helpers keep each field's accessible server error markup consistent.
          // WHY:  matching message IDs let aria-describedby announce the exact problem.
          $serverError = static function (string $field) use ($fieldError, $escape): void {
              $message = $fieldError($field);
              if ($message !== null) {
                  echo '<p id="' . $escape($field) . '-validation-message" class="field-validation-message" role="alert">'
                    . $escape($message) . '</p>';
              }
          };
$errorAttributes = static function (string $field) use ($fieldError, $escape): string {
    return $fieldError($field) === null ? '' : ' aria-invalid="true" aria-describedby="' . $escape($field) . '-validation-message"';
};
?>

          <div class="signup-field">
            <label for="first_name"><?php echo site_text('signup.first_name'); ?></label>
            <input type="text" id="first_name" name="first_name" value="<?php echo $escape($values['first_name'] ?? ''); ?>" autocomplete="given-name" maxlength="100" required<?php echo $errorAttributes('first_name'); ?>>
            <?php $serverError('first_name'); ?>
          </div>
          <div class="signup-field">
            <label for="last_name"><?php echo site_text('signup.last_name'); ?></label>
            <input type="text" id="last_name" name="last_name" value="<?php echo $escape($values['last_name'] ?? ''); ?>" autocomplete="family-name" maxlength="100" required<?php echo $errorAttributes('last_name'); ?>>
            <?php $serverError('last_name'); ?>
          </div>
          <!-- WHAT: email gets a complete row before the paired phone fields. -->
          <!-- WHY:  this keeps each related pair together and makes the form easier to scan. -->
          <div class="signup-field signup-field--wide">
            <label for="email"><?php echo site_text('checkout.email'); ?></label>
            <input type="email" id="email" name="email" value="<?php echo $escape($values['email'] ?? ''); ?>" placeholder="you@example.com" autocomplete="email" maxlength="255" required<?php echo $errorAttributes('email'); ?>>
            <?php $serverError('email'); ?>
          </div>
          <div class="signup-field">
            <label for="phone_number"><?php echo site_text('signup.primary_phone'); ?></label>
            <input type="tel" id="phone_number" name="phone_number" value="<?php echo $escape($values['phone_number'] ?? '+216 '); ?>" placeholder="+216 XX XXX XXX" autocomplete="tel" inputmode="tel" maxlength="15" data-tunisian-phone
              pattern="(?:\+216|00216)?[\s-]*(?:\d[\s-]*){8}" data-pattern-message="<?php echo site_text('signup.phone_pattern'); ?>" required<?php echo $errorAttributes('phone_number'); ?>>
            <?php $serverError('phone_number'); ?>
          </div>
          <div class="signup-field">
            <label for="second_phone_number"><?php echo site_text('signup.secondary_phone'); ?> <span>(<?php echo site_text('checkout.optional'); ?>)</span></label>
            <!-- WHAT: this is extra contact information, never a login identifier. -->
            <!-- WHY:  leaving it blank stores NULL; a supplied value follows the same Tunisian format. -->
            <input type="tel" id="second_phone_number" name="second_phone_number"
              value="<?php echo $escape($values['second_phone_number'] ?? '+216 '); ?>"
              placeholder="+216 XX XXX XXX" autocomplete="tel" inputmode="tel" maxlength="15" data-tunisian-phone
              pattern="(?:\+216|00216)?[\s-]*(?:\d[\s-]*){8}"
              data-pattern-message="<?php echo site_text('signup.phone_pattern'); ?>"
              data-secondary-phone data-same-phone-message="<?php echo site_text('signup.phone_different'); ?>"
              <?php echo $errorAttributes('second_phone_number'); ?>>
            <?php $serverError('second_phone_number'); ?>
          </div>
          <div class="signup-field">
            <label for="password"><?php echo site_text('login.password'); ?></label>
            <div class="password-control" data-field-control>
              <input type="password" id="password" name="password" placeholder="<?php echo site_text('signup.password_hint'); ?>" autocomplete="new-password" minlength="8" maxlength="4096"
                pattern="(?=.*[A-Za-z])(?=.*\d).{8,}" data-pattern-message="<?php echo site_text('signup.password_pattern'); ?>" required<?php echo $errorAttributes('password'); ?>>
              <button class="password-visibility-toggle" type="button" aria-controls="password" aria-label="<?php echo site_text('login.show_password'); ?>" aria-pressed="false" title="<?php echo site_text('login.show_password'); ?>" data-show-label="<?php echo site_text('login.show_password'); ?>" data-hide-label="<?php echo site_text('login.hide_password'); ?>" data-password-toggle><i class="password-visibility-toggle__show" data-lucide="eye" aria-hidden="true"></i><i class="password-visibility-toggle__hide" data-lucide="eye-off" aria-hidden="true"></i></button>
            </div>
            <?php $serverError('password'); ?>
          </div>
          <div class="signup-field">
            <label for="password_confirmation"><?php echo site_text('signup.confirm_password'); ?></label>
            <div class="password-control" data-field-control>
              <input type="password" id="password_confirmation" name="password_confirmation" placeholder="<?php echo site_text('signup.repeat_password'); ?>" autocomplete="new-password" minlength="8" maxlength="4096" data-match="#password" data-match-message="<?php echo site_text('signup.password_mismatch'); ?>" required<?php echo $errorAttributes('password_confirmation'); ?>>
              <button class="password-visibility-toggle" type="button" aria-controls="password_confirmation" aria-label="<?php echo site_text('login.show_password'); ?>" aria-pressed="false" title="<?php echo site_text('login.show_password'); ?>" data-show-label="<?php echo site_text('login.show_password'); ?>" data-hide-label="<?php echo site_text('login.hide_password'); ?>" data-password-toggle><i class="password-visibility-toggle__show" data-lucide="eye" aria-hidden="true"></i><i class="password-visibility-toggle__hide" data-lucide="eye-off" aria-hidden="true"></i></button>
            </div>
            <?php $serverError('password_confirmation'); ?>
          </div>
          <p class="signup-field__hint" id="password-policy">
            <?php echo site_text('signup.password_policy'); ?>
          </p>

          <div class="signup-form__section signup-field--wide">
            <h2><?php echo site_text('signup.address_title'); ?></h2>
            <p><?php echo site_text('signup.address_intro'); ?></p>
          </div>
          <div class="signup-field">
            <label for="address_label"><?php echo site_text('signup.address_label'); ?> <span>(<?php echo site_text('checkout.optional'); ?>)</span></label>
            <input type="text" id="address_label" name="address_label" value="<?php echo $escape($values['address_label'] ?? ''); ?>" placeholder="<?php echo site_text('signup.address_label_hint'); ?>" maxlength="50" <?php echo $errorAttributes('address_label'); ?>>
            <?php $serverError('address_label'); ?>
          </div>
          <div class="signup-field">
            <label for="governorate"><?php echo site_text('checkout.governorate'); ?></label>
            <select id="governorate" name="governorate" autocomplete="address-level1" required<?php echo $errorAttributes('governorate'); ?>>
              <option value=""><?php echo site_text('checkout.choose_governorate'); ?></option>
              <?php foreach ($governorates as $governorate): ?>
                <option value="<?php echo $escape($governorate); ?>" <?php echo ($values['governorate'] ?? '') === $governorate ? 'selected' : ''; ?>><?php echo $escape($governorate); ?></option>
              <?php endforeach; ?>
            </select>
            <?php $serverError('governorate'); ?>
          </div>
          <div class="signup-field signup-field--wide">
            <label for="street_address"><?php echo site_text('signup.street'); ?></label>
            <textarea id="street_address" name="street_address" rows="3" maxlength="255" autocomplete="street-address" placeholder="<?php echo site_text('signup.street_hint'); ?>" required<?php echo $errorAttributes('street_address'); ?>><?php echo $escape($values['street_address'] ?? ''); ?></textarea>
            <?php $serverError('street_address'); ?>
          </div>
          <div class="signup-field">
            <label for="delegation"><?php echo site_text('checkout.delegation'); ?></label>
            <input type="text" id="delegation" name="delegation" value="<?php echo $escape($values['delegation'] ?? ''); ?>" autocomplete="address-level2" maxlength="100" required<?php echo $errorAttributes('delegation'); ?>>
            <?php $serverError('delegation'); ?>
          </div>
          <div class="signup-field">
            <label for="postal_code"><?php echo site_text('checkout.postal'); ?></label>
            <input type="text" id="postal_code" name="postal_code" value="<?php echo $escape($values['postal_code'] ?? ''); ?>" inputmode="numeric" autocomplete="postal-code" pattern="[0-9]{4}" maxlength="4" data-pattern-message="<?php echo site_text('signup.postal_pattern'); ?>" required<?php echo $errorAttributes('postal_code'); ?>>
            <?php $serverError('postal_code'); ?>
          </div>

          <button class="signup-submit signup-field--wide" type="submit"><?php echo site_text('nav.create_account'); ?><i data-lucide="arrow-right" aria-hidden="true"></i></button>
        </form>

        <div class="ui-state-group ui-state-group--compact auth-state-group signup-field--wide" data-auth-states data-initial-state="<?php echo $escape($authInitialState); ?>" aria-live="polite" aria-atomic="true">
          <div class="ui-state" data-ui-state="empty" <?php echo $authInitialState !== 'empty' ? 'hidden' : ''; ?>><i data-lucide="user-round-plus" aria-hidden="true"></i>
            <div>
              <h3><?php echo site_text('signup.ready'); ?></h3>
              <p><?php echo site_text('signup.ready_body'); ?></p>
            </div>
          </div>
          <div class="ui-state" data-ui-state="loading" hidden><span class="ui-state__spinner" aria-hidden="true"></span>
            <div>
              <h3><?php echo site_text('signup.loading'); ?></h3>
              <p><?php echo site_text('signup.loading_body'); ?></p>
            </div>
          </div>
          <div class="ui-state ui-state--error" data-ui-state="error" role="alert" <?php echo $authInitialState !== 'error' ? 'hidden' : ''; ?>><i data-lucide="x" aria-hidden="true"></i>
            <div>
              <h3><?php echo site_text('signup.error'); ?></h3>
              <p data-ui-state-message><?php echo $escape($stateMessage); ?></p>
            </div>
          </div>
          <div class="ui-state ui-state--success" data-ui-state="success" hidden><i data-lucide="check" aria-hidden="true"></i>
            <div>
              <h3><?php echo site_text('signup.success'); ?></h3>
              <p><?php echo site_text('signup.success_body'); ?></p>
            </div>
          </div>
        </div>

        <p class="signup-login"><?php echo site_text('checkout.account_1'); ?> <a href="<?php echo BASE_URL . 'login.php'; ?>"><?php echo site_text('nav.sign_in'); ?></a></p>
        <p class="signup-note"><i data-lucide="shield-check" aria-hidden="true"></i><?php echo site_text('signup.private'); ?></p>
      </div>
    </div>
  </section>
</main>

<?php require_once VIEW_PATH . '/Layouts/footer.php'; ?>
