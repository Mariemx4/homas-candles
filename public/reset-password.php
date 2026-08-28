<?php

declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        require_once __DIR__ . '/../app/Bootstrap/password-reset.php';
        // WHAT: both reset and resend submit back to this same page.
        // WHY:  requesting another code should not make the customer re-enter
        //       an email that is already safely stored in the recovery session.
        if (isset($_POST['action']) && $_POST['action'] === 'resend') {
            $passwordResetController->resendCode($_POST);
        }
        $passwordResetController->reset($_POST);
    } catch (Throwable) {
        error_log('Password reset completion bootstrap failed.');
        require_once __DIR__ . '/../app/Bootstrap/session.php';
        (new \App\Auth\AuthFormFlash())->put('reset-password', [
            'form' => 'Password reset is temporarily unavailable. Please try again.',
        ]);
        header('Location: ' . BASE_URL . 'reset-password.php', true, 303);
        exit;
    }
}

require_once __DIR__ . '/../app/Bootstrap/session.php';

if (!isset($_SESSION['_password_reset_email']) || !is_string($_SESSION['_password_reset_email'])) {
    (new \App\Auth\AuthFormFlash())->put('forgot-password', ['form' => 'Request a verification code first.']);
    header('Location: ' . BASE_URL . 'forgot-password.php', true, 303);
    exit;
}

$resetEmail = $_SESSION['_password_reset_email'];
$emailParts = explode('@', $resetEmail, 2);
$emailLocalPart = $emailParts[0] ?? '';
$emailDomain = $emailParts[1] ?? '';
$lastFourBeforeAt = substr($emailLocalPart, -4);
$maskedResetEmail = '••••' . $lastFourBeforeAt . ($emailDomain !== '' ? '@' . $emailDomain : '');

$formState = (new \App\Auth\AuthFormFlash())->consume('reset-password');
$errors = $formState['errors'];
$success = $formState['success'];
$state = $errors !== [] ? 'error' : ($success !== null ? 'success' : 'empty');
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$fieldError = static fn(string $field): ?string => isset($errors[$field]) ? (string) $errors[$field] : null;
$pageTitle = "Reset password | Hom'As Candles";
require_once __DIR__ . '/../app/Views/Layouts/header.php';
?>

<main class="login-page reset-password-page">
  <section class="login-shell" aria-labelledby="reset-title">
    <div class="login-story" aria-hidden="true">
      <img src="<?php echo BASE_URL . 'assets/brand/candleSet.jpg'; ?>" alt="" class="login-story__image">
      <div class="login-story__overlay"></div>
      <div class="login-story__content"><span class="login-story__eyebrow">Almost there</span><blockquote>One small code,<br>a fresh password.</blockquote><p>Enter the code from your email and choose a password you have not shared.</p></div>
    </div>
    <div class="login-panel">
      <div class="login-panel__inner">
        <p class="login-kicker">Verify your email</p>
        <h1 id="reset-title">Choose a new password</h1>
        <p class="login-intro">The six-digit code expires after 10 minutes and allows five attempts.</p>

        <form class="login-form" action="<?php echo BASE_URL . 'reset-password.php'; ?>" method="POST" data-auth-form novalidate>
          <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
          <fieldset class="login-field reset-code-field">
            <legend>Verification code</legend>
            <!-- WHAT: six small inputs present one digit per box. -->
            <!-- WHY:  the controller joins their names server-side, so this remains functional without JavaScript. -->
            <div class="reset-code-inputs" data-reset-code-inputs data-field-control>
              <?php for ($position = 1; $position <= 6; $position++): ?>
                <input type="text" id="<?php echo $position === 1 ? 'code' : 'code_digit_' . $position; ?>"
                  name="code_digit_<?php echo $position; ?>" inputmode="numeric" maxlength="1" pattern="[0-9]"
                  <?php echo $position === 1 ? 'autocomplete="one-time-code" required data-validation-label="Verification code" data-code-message="Enter the complete 6-digit verification code."' : 'autocomplete="off"'; ?>
                  aria-label="Digit <?php echo $position; ?> of 6"
                  <?php echo $position === 1 && $fieldError('code') !== null ? 'aria-invalid="true" aria-describedby="code-validation-message reset-code-destination"' : ''; ?>
                  data-code-digit>
              <?php endfor; ?>
            </div>
            <p class="reset-code-destination" id="reset-code-destination">We sent the code to <strong><?php echo $escape($maskedResetEmail); ?></strong>.</p>
            <?php if ($fieldError('code') !== null): ?><p id="code-validation-message" class="field-validation-message" role="alert"><?php echo $escape($fieldError('code')); ?></p><?php endif; ?>
          </fieldset>
          <?php foreach (['password' => 'New password', 'password_confirmation' => 'Confirm new password'] as $field => $label): ?>
            <div class="login-field">
              <label for="<?php echo $field; ?>"><?php echo $label; ?></label>
              <div class="password-control" data-field-control>
                <input type="password" id="<?php echo $field; ?>" name="<?php echo $field; ?>" autocomplete="new-password" minlength="8" maxlength="4096"
                  <?php echo $field === 'password' ? 'pattern="(?=.*[A-Za-z])(?=.*\d).{8,}" data-pattern-message="Use at least 8 characters, including a letter and a number."' : 'data-match="#password" data-match-message="Passwords do not match."'; ?> required
                  <?php echo $fieldError($field) !== null ? 'aria-invalid="true" aria-describedby="' . $field . '-validation-message"' : ''; ?>>
                <button class="password-visibility-toggle" type="button" aria-controls="<?php echo $field; ?>" aria-label="Show password" aria-pressed="false" data-password-toggle><i class="password-visibility-toggle__show" data-lucide="eye"></i><i class="password-visibility-toggle__hide" data-lucide="eye-off"></i></button>
              </div>
              <?php if ($fieldError($field) !== null): ?><p id="<?php echo $field; ?>-validation-message" class="field-validation-message" role="alert"><?php echo $escape($fieldError($field)); ?></p><?php endif; ?>
            </div>
          <?php endforeach; ?>
          <button class="login-submit" type="submit">Reset password<i data-lucide="key-round" aria-hidden="true"></i></button>
        </form>

        <div class="ui-state-group ui-state-group--compact auth-state-group" data-auth-states data-initial-state="<?php echo $state; ?>" aria-live="polite">
          <div class="ui-state" data-ui-state="empty" <?php echo $state !== 'empty' ? 'hidden' : ''; ?>><i data-lucide="key-round" aria-hidden="true"></i><div><h3>Enter your code.</h3><p>Then choose a password with at least one letter and one number.</p></div></div>
          <div class="ui-state" data-ui-state="loading" hidden><span class="ui-state__spinner"></span><div><h3>Resetting your password…</h3></div></div>
          <div class="ui-state ui-state--error" data-ui-state="error" role="alert" <?php echo $state !== 'error' ? 'hidden' : ''; ?>><i data-lucide="x"></i><div><h3>We could not reset it.</h3><p data-ui-state-message><?php echo $escape($errors['form'] ?? $fieldError('code') ?? 'Please check the highlighted fields.'); ?></p></div></div>
          <div class="ui-state ui-state--success" data-ui-state="success" <?php echo $state !== 'success' ? 'hidden' : ''; ?>><i data-lucide="mail-check"></i><div><h3>Check your inbox.</h3><p><?php echo $escape($success ?? 'A code has been sent if that account exists.'); ?></p></div></div>
        </div>
        <form class="reset-resend-form" action="<?php echo BASE_URL . 'reset-password.php'; ?>" method="POST">
          <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf->token()); ?>">
          <input type="hidden" name="action" value="resend">
          <button type="submit">Request another code</button>
        </form>
      </div>
    </div>
  </section>
</main>

<?php require_once __DIR__ . '/../app/Views/Layouts/footer.php'; ?>
