<?php
require_once __DIR__ . '/../app/includes/header.php';
?>

<main>
  <section>
    <h1>Hom'As Candles</h1>
    <p>Light the moment, smell the memory.</p>
    <a href="
        <?php echo BASE_URL . 'shop.php'; ?>
      ">Order Now</a>
  </section>


  <section class="categories-cards">
    <h2>Explore Our Creations</h2>
    <div class="categories-grid">
      <article class="category-card">
        <img src="
            <?php echo BASE_URL . 'assets/images/candles.jpg'; ?>
          " alt="candles">
        <h3>Candles</h3>
        <p>Discover our candles collection</p>
        <a href="
            <?php echo BASE_URL . 'categories/candles.php'; ?>
          ">View Details</a>
      </article>

      <article class="category-card">
        <img src="
            <?php echo BASE_URL . 'assets/images/candleSets.jpg'; ?>
          " alt="Candle Sets">
        <h3>Candle Sets</h3>
        <p>Discover our candle sets collection</p>
        <a href="
            <?php echo BASE_URL . 'categories/candleSets.php'; ?>
          ">View Details</a>
      </article>

      <article class="category-card">
        <img src="
            <?php echo BASE_URL . 'assets/images/candleBouquets.jpg'; ?>
          " alt="Candle Bouquets">
        <h3>Candle Bouquets</h3>
        <p>Discover our candle bouquets suggestions</p>
        <a href="
            <?php echo BASE_URL . 'categories/candleBouquets.php'; ?>
          ">View Details</a>
      </article>

      <article class="category-card">
        <img src="
            <?php echo BASE_URL . 'assets/images/tawzi3et.jpg'; ?>
          " alt="Tawzi3et">
        <h3>Tawzi3et</h3>
        <p>Discover our tawzi3et suggestions</p>
        <a href="
            <?php echo BASE_URL . 'categories/tawzi3et.php'; ?>
          ">View Details</a>
      </article>
    </div>
  </section>
</main>

<?php
require_once __DIR__ . '/../app/includes/footer.php';
?>