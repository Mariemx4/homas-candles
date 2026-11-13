<?php

declare(strict_types=1);

// Run before starting any session or issuing a CSRF/OAuth challenge. Two local
// hostnames still have different cookies, even when they reach the same Docker app.
if (PHP_SAPI !== 'cli') {
    $canonicalGoogleConfig = require APP_PATH . '/config/google-auth.php';
    if ($canonicalGoogleConfig->configured()) {
        $canonicalRedirect = \App\Http\CanonicalOrigin::redirect($canonicalGoogleConfig->redirectUri, $_SERVER);
        if ($canonicalRedirect !== null) {
            header('Cache-Control: no-store, private');
            header('Location: ' . $canonicalRedirect['url'], true, $canonicalRedirect['status']);
            exit;
        }
    }
}
