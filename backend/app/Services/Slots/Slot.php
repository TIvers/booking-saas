<?php

declare(strict_types=1);

namespace App\Services\Slots;

use Carbon\CarbonImmutable;

/**
 * Свободный слот. Все моменты времени в UTC.
 *
 * endsAt включает буфер после услуги: именно столько времени слот будет занимать в bookings
 * (значение сразу подходит для bookings.ends_at и для exclusion constraint).
 */
final readonly class Slot
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
    ) {}

    /** @return array{starts_at: string, ends_at: string} */
    public function toArray(): array
    {
        return [
            'starts_at' => $this->startsAt->utc()->toIso8601String(),
            'ends_at' => $this->endsAt->utc()->toIso8601String(),
        ];
    }
}
