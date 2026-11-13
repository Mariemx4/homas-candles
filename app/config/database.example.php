<?php

declare(strict_types=1);

// Copy to database.local.php and enter the existing PostgreSQL credentials.
// Inside the Docker network, use the PostgreSQL container name as the host.
return [
    'host' => 'homas-postgres',
    'port' => 5432,
    'database' => 'homas_candles',
    'username' => 'your_database_role',
    'password' => 'replace_with_your_database_password',
];
