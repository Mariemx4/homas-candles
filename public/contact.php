<?php
// WHAT: the contact form page — same placeholder pattern as login.php/signup.php.
// WHY:  $contactInitialState always resolves to 'error' on submit because
//       there's no code here yet that reads $_POST and actually sends
//       anything — the error message says so explicitly further down.
$contactInitialState = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? 'error' : 'empty';
$pageTitle = "Contact | Hom'As Candles";

require_once __DIR__ . '/../app/Views/Layouts/header.php';
?>

<main class="contact-page">
  <header class="contact-hero">
    <p class="contact-eyebrow">A conversation begins here</p>
    <div class="contact-hero__heading">
      <h1>Let’s create something <em>meaningful.</em></h1>
      <p>Have a question about a piece, a personalised request, or an upcoming occasion? Tell us what you have in mind.</p>
    </div>
  </header>

  <section class="contact-shell" aria-labelledby="contact-form-title">
    <div class="contact-form-card">
      <div class="contact-form-card__heading">
        <p class="contact-eyebrow">Send a message</p>
        <h2 id="contact-form-title">We’d love to hear from you.</h2>
        <p>Share as much detail as you can. We’ll reply personally as soon as possible.</p>
      </div>

      <form class="contact-form" action="<?php echo BASE_URL . 'contact.php'; ?>" method="POST"
        data-contact-form novalidate>
        <div class="contact-form__row">
          <div class="contact-field">
            <label for="contact-name">Your name</label>
            <input type="text" id="contact-name" name="name" autocomplete="name"
              placeholder="How should we address you?" minlength="2" required>
          </div>

          <div class="contact-field">
            <label for="contact-number">Phone number</label>
            <input type="tel" id="contact-number" name="number" autocomplete="tel"
              placeholder="Your Tunisian phone number" inputmode="tel"
              pattern="(?:\+216\s?)?(?:\d[\s-]?){8}"
              data-pattern-message="Enter an 8-digit Tunisian phone number, with or without +216."
              required>
          </div>
        </div>

        <div class="contact-field">
          <label for="contact-email">Email address</label>
          <input type="email" id="contact-email" name="email" autocomplete="email"
            placeholder="you@example.com" required>
        </div>

        <div class="contact-field">
          <label for="contact-message">How can we help?</label>
          <textarea id="contact-message" name="message" rows="6"
            placeholder="Tell us about the piece, quantity, occasion, or question you have in mind."
            minlength="10" required></textarea>
        </div>

        <div class="contact-form__footer">
          <p id="contact-form-note">
            <i data-lucide="shield-check" aria-hidden="true"></i>
            Your details are used only to respond to your enquiry.
          </p>
          <button type="submit" aria-describedby="contact-form-note">
            Send message
            <i data-lucide="send" aria-hidden="true"></i>
          </button>
        </div>
      </form>

      <div class="contact-form-states" data-contact-states
        data-initial-state="<?php echo $contactInitialState; ?>" aria-live="polite" aria-atomic="true">
        <div class="contact-form-state contact-form-state--empty" data-ui-state="empty"
          <?php echo $contactInitialState !== 'empty' ? 'hidden' : ''; ?>>
          <i data-lucide="mail" aria-hidden="true"></i>
          <div>
            <h3>Whenever you’re ready.</h3>
            <p>Complete the fields above to begin your message.</p>
          </div>
        </div>

        <div class="contact-form-state contact-form-state--loading" data-ui-state="loading" hidden>
          <span class="contact-state-spinner" aria-hidden="true"></span>
          <div>
            <h3>Sending your message…</h3>
            <p>Please keep this page open for a moment.</p>
          </div>
        </div>

        <div class="contact-form-state contact-form-state--error" data-ui-state="error"
          role="alert" <?php echo $contactInitialState !== 'error' ? 'hidden' : ''; ?>>
          <i data-lucide="x" aria-hidden="true"></i>
          <div>
            <h3>We couldn’t send your message.</h3>
            <p data-ui-state-message>
              Website messaging is not available yet. Please contact us by email or phone.
            </p>
          </div>
        </div>

        <div class="contact-form-state contact-form-state--success" data-ui-state="success" hidden>
          <i data-lucide="check" aria-hidden="true"></i>
          <div>
            <h3>Your message has been received.</h3>
            <p>Thank you for reaching out. We’ll reply as soon as possible.</p>
          </div>
        </div>
      </div>
    </div>

    <aside class="contact-details" aria-labelledby="contact-details-title">
      <p class="contact-eyebrow">Reach Hom'As directly</p>
      <h2 id="contact-details-title">A personal reply, from our hands to yours.</h2>
      <p class="contact-details__intro">Prefer to contact us directly? Use whichever way feels easiest.</p>

      <ul class="contact-details__list">
        <li>
          <i data-lucide="mail" aria-hidden="true"></i>
          <div>
            <span>Email</span>
            <a href="mailto:homascandles@gmail.com">homascandles@gmail.com</a>
          </div>
        </li>
        <li>
          <i data-lucide="phone" aria-hidden="true"></i>
          <div>
            <span>Phone</span>
            <a href="tel:+21628713024">+216 28 713 024</a>
          </div>
        </li>
        <li>
          <i data-lucide="map-pin" aria-hidden="true"></i>
          <div>
            <span>Delivery</span>
            <p>Available across Tunisia</p>
          </div>
        </li>
      </ul>

      <div class="contact-details__closing">
        <i data-lucide="sparkles" aria-hidden="true"></i>
        <p>Thoughtfully handmade in Tunisia, one meaningful piece at a time.</p>
      </div>
    </aside>
  </section>
</main>

<?php
require_once __DIR__ . '/../app/Views/Layouts/footer.php';
?>
