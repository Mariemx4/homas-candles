<?php
require_once __DIR__ . '/../app/includes/header.php';
?>

<main>
  <section>
    <h1>Register</h1>
    <p>Join our community and create an account to enjoy exclusive benefits and stay updated with our latest products and offers.</p>
    <form action="register" method="POST">
      <label for="name">Name:</label>
      <input type="text" id="name" name="name" required>

      <label for="email">Email:</label>
      <input type="email" id="email" name="email" required>

      <label for="password">Password:</label>
      <input type="password" id="password" name="password" required>

      <label for="confirm_password">Confirm Password:</label>
      <input type="password" id="confirm_password" name="confirm_password" required>

      <label for="phone">Phone Number:</label>
      <input type="tel" id="phone" name="phone" required>

      <label for="address">Address:</label>
      <textarea id="address" name="address" rows="3" required></textarea>

      <button type="submit">Register</button>
    </form>
  </section>
</main>

  <?php
  require_once __DIR__ . '/../app/includes/footer.php';
  ?>