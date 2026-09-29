<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Runtime configuration for Database.example.
 *
 * DATA FLOW: Bootstrap code reads this configuration before constructing application objects. Keep secrets environment-driven and keep request or rendering logic out of this layer.
 */

// WHAT: template for the real, gitignored database.local.php.
// WHY:  a new developer copies this file to database.local.php and fills in
//       their own local MySQL credentials — this file itself is safe to
//       commit because it holds no real password.
return [
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'homas_candles',
    'username' => 'your_database_user',
    'password' => 'your_database_password',
    'charset' => 'utf8mb4',
];
