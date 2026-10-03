<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

/** Calendar-day labels use the same timezone and today supplied by the application. */
final class DeadlinePresentation
{
    /** @return array{label: string, tone: string} */
    public static function forDate(?string $value, DateTimeImmutable $today): array
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, $today->getTimezone()) : false;
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $value) {
            return ['label' => 'Not set', 'tone' => 'neutral'];
        }
        $days = (int) $today->setTime(0, 0)->diff($date)->format('%r%a');
        return [
            'label' => match (true) {
                $days < 0 => 'Overdue by ' . abs($days) . (abs($days) === 1 ? ' day' : ' days'),
                $days === 0 => 'Today',
                $days === 1 => 'Tomorrow',
                default => 'In ' . $days . ' days',
            },
            'tone' => $days < 0 ? 'danger' : ($days === 0 ? 'warning' : 'neutral'),
        ];
    }
}
