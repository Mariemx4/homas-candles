<?php

declare(strict_types=1);



$statusValue = strtoupper((string) ($status ?? 'UNKNOWN'));
$statusToneMap = [
    'REQUESTED' => 'warning',
    'SUBMITTED' => 'warning',
    'PENDING_VERIFICATION' => 'warning',
    'CONFIRMED' => 'info',
    'CONTACTED' => 'info',
    'DISCUSSING' => 'info',
    'PREPARING' => 'info',
    'AGREED' => 'success',
    'ACTIVE' => 'success',
    'PREPARED' => 'success',
    'WAITING_FOR_PICKUP' => 'success',
    'IN_DELIVERY' => 'success',
    'PICKED_UP' => 'neutral',
    'DELIVERED' => 'neutral',
    'CONVERTED' => 'neutral',
    'DECLINED' => 'danger',
    'CANCELLED' => 'danger',
    'SUSPENDED' => 'danger',
    'REJECTED' => 'danger',
];
$statusTone = $statusToneMap[$statusValue] ?? 'neutral';
$statusLabel = ucwords(strtolower(str_replace('_', ' ', $statusValue)));
?>
<span class="admin-badge admin-badge--<?php echo $escape($statusTone); ?>"><?php echo $escape($statusLabel); ?></span>