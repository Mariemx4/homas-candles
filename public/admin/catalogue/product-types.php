<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-catalogue.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
$taxonomyKind = 'product_types';

require __DIR__ . '/_taxonomy.php';
