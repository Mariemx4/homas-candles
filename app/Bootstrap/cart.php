<?php

declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/product.php';

use App\Services\CartService;

$cartService = new CartService($productService);
