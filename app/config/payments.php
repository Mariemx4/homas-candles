<?php

declare(strict_types=1);

// Protected server configuration. Never place account details in public templates.
// payments.local.php is ignored by Git and may override these environment values.
$localPath = __DIR__ . '/payments.local.php';
$local = is_file($localPath) ? require $localPath : [];
$local = is_array($local) ? $local : [];
$settings = [];
foreach (['account_holder' => 'HOMAS_D17_ACCOUNT_HOLDER', 'number' => 'HOMAS_D17_NUMBER', 'account_information' => 'HOMAS_D17_ACCOUNT_INFORMATION'] as $key => $environment) {
    $value = $local[$key] ?? getenv($environment);
    $settings[$key] = is_string($value) ? trim($value) : '';
}
$settings['available'] = $settings['account_holder'] !== '' && preg_match('/^[0-9]{8}$/', $settings['number']) === 1;
return $settings;
