<?php
require_once __DIR__ . '/../config/config.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Hom'As Candles</title>
  <link rel="stylesheet" href="
      <?php echo BASE_URL . 'assets/css/style.css'; ?>
    ">
</head>

<body>
  <header>
    <nav aria-label="Main navigation">
      <a href="index.php">Hom'As Candles</a>

      <ul>
        <li><a href="
          <?php echo BASE_URL . 'login.php'; ?>
        ">Log In</a></li>
        <li><a href="
          <?php echo BASE_URL . 'register.php'; ?>
        ">Register</a></li>
      </ul>

      <ul>
        <li><a href="
          <?php echo BASE_URL . 'index.php'; ?>
        ">Home</a></li>
        <li><a href="
          <?php echo BASE_URL . 'shop.php'; ?>
        ">Shop</a></li>
        <li><a href="contact.php">Contact</a></li>
      </ul>

      <!--<section class="categories">
          <h2>Categories</h2>
          <ul>
            <li><a href="candles.php">Candles</a></li>
            <li><a href="candleSets.php">Candle Sets</a></li>
            <li><a href="candleBouquets.php">Candle Bouquets</a></li>
            <li><a href="tawzi3et.php">Tawzi3et</a></li>
          </ul>
        </section>-->
    </nav>
  </header>

  <!-- we don't close the body and html tags here because we want to include the footer.php file which will close them -->