<?php

declare(strict_types=1);
require_once __DIR__ . '/../../../app/Bootstrap/admin/admin-catalogue.php';
$administrator = $administratorAuthorization->requireActiveAdministrator();
$taxonomyKind = 'colors';

require APP_PATH . '/Bootstrap/admin/taxonomy-page.php';
