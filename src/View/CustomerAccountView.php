<?php

declare(strict_types=1);

namespace App\View;

use App\Services\Money;
use App\Services\Locale;
use DateTimeImmutable;
use Throwable;

/** Display metadata only; order changes and payment eligibility stay on the server. */
final class CustomerAccountView
{
    private const ICONS = [
        'home' => '<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>',
        'bag' => '<path d="M5 7h14l1 14H4L5 7Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
        'pin' => '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
        'arrow' => '<path d="M5 12h14m-6-6 6 6-6 6"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'edit' => '<path d="m16 3 5 5-12 12-6 1 1-6Z"/><path d="m14 5 5 5"/>',
        'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 0 1 5 .5c0 2-2.5 2-2.5 4M12 17h.01"/>',
        'shield' => '<path d="m12 2 9 4v6c0 6-9 10-9 10S3 18 3 12V6l9-4Z"/><path d="m8 12 3 3 5-6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
    ];

    public static function icon(string $name): string
    {
        return '<svg class="account-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . (self::ICONS[$name] ?? self::ICONS['bag']) . '</svg>';
    }

    public static function initials(array $account): string
    {
        return mb_strtoupper(mb_substr((string) $account['first_name'], 0, 1) . mb_substr((string) $account['last_name'], 0, 1));
    }

    public static function date(mixed $value): string
    {
        try {
            return is_string($value) && $value !== '' ? (new DateTimeImmutable($value))->format('d/m/Y') : '—';
        } catch (Throwable) {
            return '—';
        }
    }

    /** Translate known validation categories without exposing implementation errors. */
    public static function profileError(string $field, string $message): string
    {
        $key = match ($field) {
            'first_name', 'last_name' => 'name',
            'email' => 'email',
            'phone_number' => 'phone',
            'second_email' => 'optional_email',
            'second_phone_number' => 'optional_phone',
            'address' => str_contains($message, 'default') ? 'default' : 'address',
            default => str_contains($message, 'changed elsewhere') ? 'stale'
                : (str_contains($message, 'already used') ? 'contact_conflict' : 'generic'),
        };
        return Locale::text('account.error_' . $key);
    }

    /** Pending transfers are never presented as paid or as a request to pay twice. */
    public static function orderState(array $order): array
    {
        $status = (string) $order['order_status'];
        $stage = match ($status) {
            'CONFIRMED' => 1,
            'PREPARING', 'PREPARED' => 2,
            'WAITING_FOR_PICKUP', 'IN_DELIVERY' => 3,
            'PICKED_UP', 'DELIVERED' => 4,
            default => 0,
        };
        $state = ['stage' => $stage, 'tone' => 'neutral', 'help' => 'account.hint_' . strtolower($status),
            'action' => 'account.open_order', 'href' => 'account-order.php?id=' . (int) $order['id']];
        if ($status === 'CANCELLED') {
            return array_replace($state, ['tone' => 'muted']);
        }
        if (in_array($status, ['PICKED_UP', 'DELIVERED'], true)) {
            return array_replace($state, ['tone' => 'complete']);
        }
        if (in_array($status, ['WAITING_FOR_PICKUP', 'IN_DELIVERY'], true)) {
            return array_replace($state, ['tone' => 'attention']);
        }
        $paid = Money::parseMillis((string) ($order['amount_paid'] ?? '0')) ?? 0;
        $pending = Money::parseMillis((string) ($order['pending_amount'] ?? '0')) ?? 0;
        $deposit = Money::parseMillis((string) ($order['required_deposit_amount'] ?? '0')) ?? 0;
        if ($pending > 0) {
            return array_replace($state, ['help' => 'account.hint_payment_review']);
        }
        if ($deposit > $paid && in_array($status, ['REQUESTED', 'CONFIRMED'], true)) {
            return array_replace($state, ['tone' => 'attention', 'help' => 'account.hint_deposit', 'action' => 'account.pay_deposit',
                'href' => 'order-payment.php?order_id=' . (int) $order['id']]);
        }
        return $state;
    }
}
