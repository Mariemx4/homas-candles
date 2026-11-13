<?php

declare(strict_types=1);



// WHAT: the business's public contact channels and the contact form's
//       abuse limits, shared by contact.php, the product page, and
//       ContactRequestService.
// WHY:  one list keeps the "reach us directly" sidebar and the product
//       page's social buttons identical. A channel set to null is simply
//       not rendered, so an unknown URL is never guessed.
return [
    'email' => 'homascandles@gmail.com',
    // Digits only, international format without "+"; used for tel: and wa.me links.
    'phone_international' => '21628713024',
    'phone_display' => '+216 28 713 024',

    // Documented social-contact options (docs/database-requirements.md).
    'channels' => [
        'WHATSAPP' => 'https://wa.me/21628713024',
        'INSTAGRAM' => 'https://www.instagram.com/homas_candles/',
        'FACEBOOK' => 'https://www.facebook.com/profile.php?id=61574948525165',
        // TODO: add the official TikTok profile URL; hidden until it is set.
        'TIKTOK' => null,
    ],

    // ABUSE CONTROLS: tuned for a small shop — generous for real customers,
    // tight enough that a script cannot flood the request queue.
    'minimum_fill_seconds' => 3,        // faster than this is almost certainly a bot
    'session_limit' => 3,               // submissions per browser session...
    'session_window_seconds' => 600,    // ...within 10 minutes
    'phone_limit' => 3,                 // open web requests per phone number...
    'phone_window_seconds' => 86400,    // ...within 24 hours
    'global_limit' => 60,               // all web requests site-wide...
    'global_window_seconds' => 600,     // ...within 10 minutes (flood breaker)
    'maximum_links' => 2,               // URLs allowed in one message
    'message_max_length' => 3000,
];
