<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Jobs\SendTelegramNotification;
use App\Models\Booking;
use Carbon\CarbonImmutable;

class ScheduleTelegramReminders
{
    public function execute(Booking $booking): void
    {
        $tenantId = (int) app('currentTenant')->getKey();
        $startsAt = CarbonImmutable::instance($booking->starts_at)->utc();

        foreach ([
            'reminder_24h' => $startsAt->subDay(),
            'reminder_2h' => $startsAt->subHours(2),
        ] as $type => $sendAt) {
            if ($sendAt->isFuture()) {
                SendTelegramNotification::dispatch(
                    (int) $booking->getKey(),
                    $tenantId,
                    $type,
                    $startsAt->toIso8601String(),
                )->delay($sendAt)->afterCommit();
            }
        }
    }
}
