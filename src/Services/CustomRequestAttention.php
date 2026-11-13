<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The status badge shown next to a custom request in the inbox and on its page.
 *
 * Rules are checked in priority order: archived, closed, follow-up due,
 * awaiting a first response, required date passed, follow-up scheduled.
 * Follow-up times are stored in UTC; the required date is compared with the
 * Tunisia business day.
 */
final class CustomRequestAttention
{
    /**
     * @return array{label: string, tone: string}
     */
    public static function for(array $request, DateTimeImmutable $now): array
    {
        if (!empty($request['archived_at'])) {
            return ['label' => 'Archived','tone' => 'neutral'];
        }
        if (in_array((string) $request['request_status'], CustomRequestService::TERMINAL_STATUSES, true)) {
            return ['label' => 'Closed', 'tone' => 'neutral'];
        }
        $followUp = $request['next_follow_up_at'] ?? null;
        if (is_string($followUp) && $followUp !== '' && $followUp <= $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')) {
            return ['label' => 'Follow-up due', 'tone' => 'danger'];
        }
        if ((string) $request['request_status'] === 'SUBMITTED') {
            return ['label' => 'Response needed', 'tone' => 'warning'];
        }
        $requiredDate = $request['required_date'] ?? null;
        if (is_string($requiredDate) && $requiredDate !== '' && $requiredDate < BusinessClock::today($now)->format('Y-m-d')) {
            return ['label' => 'Date overdue', 'tone' => 'danger'];
        }
        if (is_string($followUp) && $followUp !== '') {
            return ['label' => 'Follow-up scheduled', 'tone' => 'info'];
        }

        return ['label' => 'In progress', 'tone' => 'neutral'];
    }
}
