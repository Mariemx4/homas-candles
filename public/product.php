<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Bootstrap/product.php';

$view = $productController->show((string) ($_GET['slug'] ?? ''));
extract($view, EXTR_SKIP);

require __DIR__ . '/../app/Views/Pages/product.php';
