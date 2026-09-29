<?php

declare(strict_types=1);

/**
 * FILE PURPOSE: Runtime configuration for Mail.
 *
 * DATA FLOW: Bootstrap code reads this configuration before constructing application objects. Keep secrets environment-driven and keep request or rendering logic out of this layer.
 */

// WHAT: non-secret sender identity for password reset emails.
// WHY:  APP_MAIL_FROM can be configured per environment without editing code.
return [
    'from_address' => getenv('APP_MAIL_FROM') ?: 'no-reply@homas-candles.local',
    'from_name' => "Hom'As Candles",
];
