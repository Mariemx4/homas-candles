<?php

declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../config/database.php';

use App\Controllers\CustomerAccountController;
use App\Repositories\CustomerAccountReadRepository;

$customerAccountController = new CustomerAccountController(new CustomerAccountReadRepository(database()));
