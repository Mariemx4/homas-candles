<?php
require_once __DIR__ . '/../app/includes/header.php';
?>

<main>
  <section>
    <h1>Contact Us</h1>
    <h3>Have questions or inquiries? We'd love to hear from you!</h3>
    <p>Please fill out the form below, or send us a message at
      <a href="mailto:homascandles@gmail.com">homascandles@gmail.com</a>.
      We will get back to you as soon as possible.
    </p>

    <form action="contact_us" method="POST">
      <label for="name">Name:</label>
      <input type="text" id="name" name="name" required>

      <label for="number">Phone Number:</label>
      <input type="tel" id="number" name="number" required>

      <label for="email">Email:</label>
      <input type="email" id="email" name="email" required>

      <label for="message">Message:</label>
      <textarea id="message" name="message" rows="5" required></textarea>

      <button type="submit">Send Message</button>
    </form>
  </section>
</main>


<?php
require_once __DIR__ . '/../app/includes/footer.php';
?>