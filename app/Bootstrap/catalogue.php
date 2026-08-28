<?php

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

use App\Controllers\CatalogueController;

$catalogueProductTypes = require __DIR__ . '/../config/catalogue.php';
$catalogueController = new CatalogueController($catalogueProductTypes);
