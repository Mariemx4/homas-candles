<?php

declare(strict_types=1);
require_once __DIR__ . '/../app/Bootstrap/catalogue.php';

$view = $catalogueController->shop($_GET);
extract($view, EXTR_SKIP);

require VIEW_PATH . '/Pages/shop.php';
