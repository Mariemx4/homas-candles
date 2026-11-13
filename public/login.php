<?php

declare(strict_types=1);

use App\View\Presentation;

// WHAT: POST requests are handled before any HTML is sent.
// WHY:  the controller can then use POST/Redirect/GET without "headers already sent" errors.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        require_once __DIR__ . '/../app/Bootstrap/customer-auth.php';
        $customerAuthController->login($_POST);
    } catch (Throwable) {
        // Keep database/bootstrap failures inside the normal safe form flow.
        error_log('Customer login bootstrap failed.');
        require_once __DIR__ . '/../app/Bootstrap/session.php';
        $safeIdentifier = isset($_POST['login_identifier']) && is_string($_POST['login_identifier'])
            ? trim($_POST['login_identifier'])
            : '';
        (new \App\Auth\AuthFormFlash())->put(
            'login',
            ['form' => 'Sign-in is temporarily unavailable. Please try again.'],
            ['login_identifier' => $safeIdentifier]
        );
        header('Location: ' . BASE_URL . 'login.php', true, 303);
        exit;
    }
}
require_once __DIR__ . '/../app/Bootstrap/session.php';

use App\Auth\AuthFormFlash;

$formState = (new AuthFormFlash())->consume('login');
$errors = $formState['errors'];
$values = $formState['values'];
$successMessage = $formState['success'];

if (isset($_GET['session_expired'])) {
    $errors['form'] = \App\Services\Locale::text('login.session_expired');
}
if (isset($_GET['signed_out'])) {
    $successMessage = \App\Services\Locale::text('login.signed_out');
}

$authInitialState = $errors !== [] ? 'error' : ($successMessage !== null ? 'success' : 'empty');
$stateMessage = $errors['authentication'] ?? $errors['form'] ?? \App\Services\Locale::text('contact.validation_error');
$pageTitle = \App\Services\Locale::text('nav.sign_in') . " | Hom'As Candles";
$escape = Presentation::escape(...);
$fieldError = static fn (string $field): ?string => isset($errors[$field]) && is_string($errors[$field]) ? $errors[$field] : null;

require_once VIEW_PATH . '/Layouts/header.php';
?>

<main id="main-content" tabindex="-1" class="login-page">
  <section class="login-shell" aria-labelledby="login-title">
    <div class="login-story" aria-hidden="true">
      <img src="<?php echo BASE_URL . 'assets/brand/candleSet.jpg'; ?>" alt="" class="login-story__image">
      <div class="login-story__overlay"></div>
      <div class="login-story__content">
        <span class="login-story__eyebrow"><?php echo site_text('footer.hand_poured'); ?></span>
        <blockquote><?php echo site_text('login.quote_1'); ?><br><?php echo site_text('login.quote_2'); ?></blockquote>
        <p><?php echo site_text('login.story'); ?></p>
      </div>
    </div>

    <div class="login-panel">
      <div class="login-panel__inner">
        <p class="login-kicker"><?php echo site_text('login.welcome'); ?></p>
        <h1 id="login-title"><?php echo site_text('login.title'); ?></h1>
        <p class="login-intro"><?php echo site_text('login.intro'); ?></p>

        <?php require VIEW_PATH . '/Components/google-sign-in.php'; ?>

        <?php if (count($errors) > 1): ?>
          <div class="auth-error-summary" role="alert" tabindex="-1" data-error-summary>
            <h2><?php echo site_text('login.check'); ?></h2>
            <ul>
              <?php foreach ($errors as $field => $message): ?>
                <li><a href="#<?php echo $escape(in_array($field, ['authentication', 'form'], true) ? 'login_identifier' : $field); ?>"><?php echo $escape($message); ?></a></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <form class="login-form" action="<?php echo BASE_URL . 'login.php'; ?>" method="POST" data-auth-form
          data-validation-error="<?php echo site_text('contact.validation_error'); ?>" novalidate>
          <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
          <div class="login-field">
            <label for="login_identifier"><?php echo site_text('login.identifier'); ?></label>
            <input type="text" id="login_identifier" name="login_identifier"
              value="<?php echo $escape($values['login_identifier'] ?? ''); ?>"
              placeholder="you@example.com or +216 XX XXX XXX" autocomplete="username" maxlength="255" required
              data-login-identifier data-identifier-message="<?php echo site_text('login.identifier_error'); ?>"
              <?php echo $fieldError('login_identifier') !== null ? 'aria-invalid="true" aria-describedby="login_identifier-validation-message"' : ''; ?>>
            <?php if ($fieldError('login_identifier') !== null): ?>
              <p id="login_identifier-validation-message" class="field-validation-message" role="alert"><?php echo $escape($fieldError('login_identifier')); ?></p>
            <?php endif; ?>
          </div>

          <div class="login-field">
            <div class="login-field__heading">
              <label for="password"><?php echo site_text('login.password'); ?></label>
              <a href="<?php echo BASE_URL . 'forgot-password.php'; ?>"><?php echo site_text('login.forgot'); ?></a>
            </div>
            <div class="password-control" data-field-control>
              <input type="password" id="password" name="password" placeholder="<?php echo site_text('login.password_hint'); ?>" autocomplete="current-password" maxlength="4096" required
                <?php echo $fieldError('password') !== null ? 'aria-invalid="true" aria-describedby="password-validation-message"' : ''; ?>>
              <button class="password-visibility-toggle" type="button" aria-controls="password" aria-label="<?php echo site_text('login.show_password'); ?>" aria-pressed="false" title="<?php echo site_text('login.show_password'); ?>" data-show-label="<?php echo site_text('login.show_password'); ?>" data-hide-label="<?php echo site_text('login.hide_password'); ?>" data-password-toggle>
                <i class="password-visibility-toggle__show" data-lucide="eye" aria-hidden="true"></i>
                <i class="password-visibility-toggle__hide" data-lucide="eye-off" aria-hidden="true"></i>
              </button>
            </div>
            <?php if ($fieldError('password') !== null): ?>
              <p id="password-validation-message" class="field-validation-message" role="alert"><?php echo $escape($fieldError('password')); ?></p>
            <?php endif; ?>
          </div>

          <button class="login-submit" type="submit"><?php echo site_text('nav.sign_in'); ?><i data-lucide="arrow-right" aria-hidden="true"></i></button>
        </form>

        <div class="ui-state-group ui-state-group--compact auth-state-group" data-auth-states data-initial-state="<?php echo $escape($authInitialState); ?>" aria-live="polite" aria-atomic="true">
          <div class="ui-state" data-ui-state="empty" <?php echo $authInitialState !== 'empty' ? 'hidden' : ''; ?>><i data-lucide="user-round" aria-hidden="true"></i><div><h3><?php echo site_text('login.ready'); ?></h3><p><?php echo site_text('login.ready_body'); ?></p></div></div>
          <div class="ui-state" data-ui-state="loading" hidden><span class="ui-state__spinner" aria-hidden="true"></span><div><h3><?php echo site_text('login.loading'); ?></h3><p><?php echo site_text('login.loading_body'); ?></p></div></div>
          <div class="ui-state ui-state--error" data-ui-state="error" role="alert" <?php echo $authInitialState !== 'error' ? 'hidden' : ''; ?>><i data-lucide="x" aria-hidden="true"></i><div><h3><?php echo site_text('login.error'); ?></h3><p data-ui-state-message><?php echo $escape($stateMessage); ?></p></div></div>
          <div class="ui-state ui-state--success" data-ui-state="success" <?php echo $authInitialState !== 'success' ? 'hidden' : ''; ?>><i data-lucide="check" aria-hidden="true"></i><div><h3><?php echo site_text('login.success'); ?></h3><p><?php echo $escape($successMessage ?? \App\Services\Locale::text('login.welcome_back')); ?></p></div></div>
        </div>

        <p class="login-signup"><?php echo site_text('login.new'); ?> <a href="<?php echo BASE_URL . 'signup.php'; ?>"><?php echo site_text('nav.create_account'); ?></a></p>
        <p class="login-note"><i data-lucide="sparkles" aria-hidden="true"></i><?php echo site_text('footer.tagline'); ?><i data-lucide="sparkles" aria-hidden="true"></i></p>
      </div>
    </div>
  </section>
</main>

<?php require_once VIEW_PATH . '/Layouts/footer.php'; ?>
