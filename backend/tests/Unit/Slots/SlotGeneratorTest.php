<?php

declare(strict_types=1);

use App\Services\Slots\Slot;
use App\Services\Slots\SlotGenerator;
use App\Services\Slots\TimeInterval;
use Carbon\CarbonImmutable;

function slotStarts(array $slots): array
{
    return array_map(
        fn (Slot $slot): string => $slot->startsAt->utc()->format('Y-m-d H:i'),
        $slots,
    );
}

it('generates a normal day grid and includes the last slot that fits', function () {
    $slots = (new SlotGenerator(stepMinutes: 30))->compute(
        date: CarbonImmutable::parse('2026-06-01'),
        timezone: 'Europe/Berlin',
        workRanges: [['09:00', '12:00']],
        breakRanges: [],
        busy: [],
        durationMinutes: 60,
        bufferMinutes: 0,
        now: CarbonImmutable::parse('2026-05-31 12:00:00', 'UTC'),
    );

    expect(slotStarts($slots))->toBe([
        '2026-06-01 07:00',
        '2026-06-01 07:30',
        '2026-06-01 08:00',
        '2026-06-01 08:30',
        '2026-06-01 09:00',
    ]);
});

it('returns no slots when the working window is shorter than the service', function () {
    $slots = (new SlotGenerator)->compute(
        date: CarbonImmutable::parse('2026-06-01'),
        timezone: 'Europe/Berlin',
        workRanges: [['09:00', '09:30']],
        breakRanges: [],
        busy: [],
        durationMinutes: 60,
        bufferMinutes: 0,
        now: CarbonImmutable::parse('2026-05-31 12:00:00', 'UTC'),
    );

    expect($slots)->toBe([]);
});

it('subtracts a break and restarts the grid after it', function () {
    $slots = (new SlotGenerator(stepMinutes: 60))->compute(
        date: CarbonImmutable::parse('2026-06-01'),
        timezone: 'Europe/Berlin',
        workRanges: [['09:00', '17:00']],
        breakRanges: [['12:00', '13:00']],
        busy: [],
        durationMinutes: 60,
        bufferMinutes: 0,
        now: CarbonImmutable::parse('2026-05-31 12:00:00', 'UTC'),
    );

    expect(slotStarts($slots))->toBe([
        '2026-06-01 07:00',
        '2026-06-01 08:00',
        '2026-06-01 09:00',
        '2026-06-01 11:00',
        '2026-06-01 12:00',
        '2026-06-01 13:00',
        '2026-06-01 14:00',
    ]);
});

it('allows a slot immediately after a busy interval', function () {
    $slots = (new SlotGenerator(stepMinutes: 30))->compute(
        date: CarbonImmutable::parse('2026-06-01'),
        timezone: 'Europe/Berlin',
        workRanges: [['09:00', '12:00']],
        breakRanges: [],
        busy: [
            new TimeInterval(
                CarbonImmutable::parse('2026-06-01 08:00:00', 'UTC'),
                CarbonImmutable::parse('2026-06-01 09:00:00', 'UTC'),
            ),
        ],
        durationMinutes: 60,
        bufferMinutes: 0,
        now: CarbonImmutable::parse('2026-05-31 12:00:00', 'UTC'),
    );

    expect(slotStarts($slots))->toBe([
        '2026-06-01 07:00',
        '2026-06-01 09:00',
    ]);
});

it('accounts for the service buffer when checking busy intervals', function () {
    $slots = (new SlotGenerator(stepMinutes: 30))->compute(
        date: CarbonImmutable::parse('2026-06-01'),
        timezone: 'Europe/Berlin',
        workRanges: [['09:00', '11:00']],
        breakRanges: [],
        busy: [
            new TimeInterval(
                CarbonImmutable::parse('2026-06-01 08:00:00', 'UTC'),
                CarbonImmutable::parse('2026-06-01 08:30:00', 'UTC'),
            ),
        ],
        durationMinutes: 30,
        bufferMinutes: 30,
        now: CarbonImmutable::parse('2026-05-31 12:00:00', 'UTC'),
    );

    expect(slotStarts($slots))->toBe([
        '2026-06-01 07:00',
        '2026-06-01 08:30',
    ]);
});

it('filters slots by the minimum lead time', function () {
    $slots = (new SlotGenerator(stepMinutes: 30, minLeadMinutes: 120))->compute(
        date: CarbonImmutable::parse('2026-06-01'),
        timezone: 'Europe/Berlin',
        workRanges: [['09:00', '11:00']],
        breakRanges: [],
        busy: [],
        durationMinutes: 30,
        bufferMinutes: 0,
        now: CarbonImmutable::parse('2026-06-01 05:30:00', 'UTC'),
    );

    expect(slotStarts($slots))->toBe([
        '2026-06-01 07:30',
        '2026-06-01 08:00',
        '2026-06-01 08:30',
    ]);
});

it('converts a local slot to UTC on a daylight-saving day', function () {
    $slots = (new SlotGenerator(stepMinutes: 60))->compute(
        date: CarbonImmutable::parse('2026-03-29'),
        timezone: 'Europe/Berlin',
        workRanges: [['09:00', '10:00']],
        breakRanges: [],
        busy: [],
        durationMinutes: 60,
        bufferMinutes: 0,
        now: CarbonImmutable::parse('2026-03-28 12:00:00', 'UTC'),
    );

    expect(slotStarts($slots))->toBe(['2026-03-29 07:00']);
});

it('rejects invalid duration and negative buffer values', function () {
    $generator = new SlotGenerator;
    $arguments = [
        'date' => CarbonImmutable::parse('2026-06-01'),
        'timezone' => 'Europe/Berlin',
        'workRanges' => [['09:00', '10:00']],
        'breakRanges' => [],
        'busy' => [],
        'durationMinutes' => 0,
        'bufferMinutes' => 0,
        'now' => CarbonImmutable::parse('2026-05-31 12:00:00', 'UTC'),
    ];

    expect(fn () => $generator->compute(...$arguments))
        ->toThrow(InvalidArgumentException::class);
});
