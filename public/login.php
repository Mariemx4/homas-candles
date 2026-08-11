<?php
  require_once __DIR__ . '/../app/includes/header.php';
?>

<main>
  <section>
    <h1>Log In</h1>
    <p>Welcome back! Please enter your credentials to access your account and continue exploring our products.</p>
    <form action="login" method="POST">
      <label for="email">Email:</label>
      <input type="email" id="email" name="email" required>

      <label for="password">Password:</label>
      <input type="password" id="password" name="password" required>

      <button type="submit">Log In</button>
    </form>
  </section>
</main>

<?php
  require_once __DIR__ . '/../app/includes/footer.php';
?>