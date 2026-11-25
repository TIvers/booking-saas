<?php

declare(strict_types=1);

namespace App\Services\Slots;

use App\Models\Booking;
use App\Models\LocationScheduleClosure;
use App\Models\LocationWorkSchedule;
use App\Models\ScheduleBreak;
use App\Models\ScheduleException;
use App\Models\Service;
use App\Models\Staff;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Генерация свободных слотов сотрудника на день.
 *
 * Класс разделён на две части:
 *  - forDay() загружает данные из БД и выбирает расписание на дату (график, исключение, перерывы);
 *  - compute() содержит чистую логику без обращений к БД, её удобно покрывать юнит-тестами.
 *
 * Правила:
 *  - рабочие часы и перерывы задаются локальным временем филиала, результат в UTC;
 *  - услуга (duration) должна целиком помещаться в рабочее окно вне перерывов;
 *  - занимаемый интервал слота равен duration + buffer и не должен пересекаться с занятыми записями
 *    (это то же условие, что проверяет exclusion constraint на bookings);
 *  - сетка слотов отсчитывается от начала каждого рабочего окна (после перерыва сетка стартует заново);
 *  - слоты ближе чем $minLeadMinutes к текущему моменту не предлагаются.
 */
final class SlotGenerator
{
    public function __construct(
        private readonly int $stepMinutes = 15,
        private readonly int $minLeadMinutes = 0,
    ) {
        if ($stepMinutes < 1) {
            throw new InvalidArgumentException('Шаг сетки должен быть не меньше одной минуты.');
        }
    }

    /**
     * @return list<Slot>
     */
    public function forDay(
        Staff $staff,
        Service $service,
        CarbonImmutable $date,
        ?CarbonImmutable $now = null,
        ?int $excludeBookingId = null,
    ): array {
        $timezone = $staff->location->timezone;
        $now ??= CarbonImmutable::now('UTC');

        // Берём только календарную дату и трактуем её в таймзоне филиала.
        $localStart = CarbonImmutable::parse($date->format('Y-m-d'), $timezone)->startOfDay();
        $localEnd = $localStart->addDay();
        $day = $localStart->format('Y-m-d');
        $weekday = $localStart->dayOfWeekIso; // 1 (пн) ... 7 (вс)

        $workRanges = $this->resolveWorkRanges($staff, $day, $weekday);
        $locationRanges = $this->resolveLocationWorkRanges($staff, $day, $weekday);
        $workRanges = $this->intersectRanges($workRanges, $locationRanges);

        if ($workRanges === []) {
            return [];
        }

        $breakRanges = ScheduleBreak::query()
            ->where('staff_id', $staff->id)
            ->where('weekday', $weekday)
            ->get(['start_time', 'end_time'])
            ->map(fn (ScheduleBreak $b): array => [(string) $b->start_time, (string) $b->end_time])
            ->all();

        $busy = Booking::query()
            ->active()
            ->where('staff_id', $staff->id)
            ->when($excludeBookingId !== null, fn ($query) => $query->where('bookings.id', '<>', $excludeBookingId))
            ->where('starts_at', '<', $localEnd->utc())
            ->where('ends_at', '>', $localStart->utc())
            ->get(['starts_at', 'ends_at'])
            ->map(fn (Booking $b): TimeInterval => new TimeInterval(
                CarbonImmutable::instance($b->starts_at)->utc(),
                CarbonImmutable::instance($b->ends_at)->utc(),
            ))
            ->all();

        return $this->compute(
            date: $localStart,
            timezone: $timezone,
            workRanges: $workRanges,
            breakRanges: $breakRanges,
            busy: $busy,
            durationMinutes: (int) $service->duration_min,
            bufferMinutes: (int) $service->buffer_min,
            now: $now,
        );
    }

    /**
     * Чистая логика генерации слотов.
     *
     * @param  CarbonImmutable  $date  Календарная дата в таймзоне филиала (время суток игнорируется).
     * @param  list<array{0: string, 1: string}>  $workRanges  Рабочие часы, локальное время 'H:i' или 'H:i:s'.
     * @param  list<array{0: string, 1: string}>  $breakRanges  Перерывы, локальное время.
     * @param  list<TimeInterval>  $busy  Занятые интервалы в UTC (с учётом буфера записей).
     * @return list<Slot>
     */
    public function compute(
        CarbonImmutable $date,
        string $timezone,
        array $workRanges,
        array $breakRanges,
        array $busy,
        int $durationMinutes,
        int $bufferMinutes,
        CarbonImmutable $now,
    ): array {
        if ($durationMinutes < 1 || $bufferMinutes < 0) {
            throw new InvalidArgumentException('Длительность должна быть положительной, буфер неотрицательным.');
        }

        $day = $date->format('Y-m-d');

        // 1. Рабочие окна минус перерывы.
        $windows = $this->mergeIntervals(
            array_map(fn (array $r): TimeInterval => $this->toInterval($day, $r, $timezone), $workRanges),
        );

        $breaks = array_map(fn (array $r): TimeInterval => $this->toInterval($day, $r, $timezone), $breakRanges);
        $windows = $this->subtractAll($windows, $breaks);

        // 2. Нарезка сеткой с проверкой занятости и минимального срока до записи.
        $earliestStart = $now->utc()->addMinutes($this->minLeadMinutes);
        $step = $this->stepMinutes;
        $slots = [];

        foreach ($windows as $window) {
            $start = $window->start;

            while (true) {
                $serviceEnd = $start->addMinutes($durationMinutes);

                // Услуга должна целиком помещаться в рабочее окно.
                if ($serviceEnd > $window->end) {
                    break;
                }

                $occupied = new TimeInterval($start, $serviceEnd->addMinutes($bufferMinutes));

                if ($start >= $earliestStart && ! $this->overlapsAny($occupied, $busy)) {
                    $slots[] = new Slot($occupied->start, $occupied->end);
                }

                $start = $start->addMinutes($step);
            }
        }

        return $slots;
    }

    /**
     * Рабочие часы на дату: исключение имеет приоритет над недельным графиком.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function resolveWorkRanges(Staff $staff, string $day, int $weekday): array
    {
        $exception = ScheduleException::query()
            ->where('staff_id', $staff->id)
            ->where('date', $day)
            ->first();

        if ($exception !== null) {
            if ($exception->is_day_off) {
                return [];
            }

            if ($exception->start_time !== null && $exception->end_time !== null) {
                return [[(string) $exception->start_time, (string) $exception->end_time]];
            }
        }

        return WorkSchedule::query()
            ->where('staff_id', $staff->id)
            ->where('weekday', $weekday)
            ->get(['start_time', 'end_time'])
            ->map(fn (WorkSchedule $w): array => [(string) $w->start_time, (string) $w->end_time])
            ->all();
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function resolveLocationWorkRanges(Staff $staff, string $day, int $weekday): array
    {
        if (
            LocationScheduleClosure::query()
                ->where('location_id', $staff->location_id)
                ->whereDate('date', $day)
                ->exists()
        ) {
            return [];
        }

        $schedule = LocationWorkSchedule::query()
            ->where('location_id', $staff->location_id)
            ->where('weekday', $weekday)
            ->first();

        if ($schedule === null) {
            return $weekday <= 5 ? [['10:00', '18:00']] : [];
        }

        if ($schedule->is_closed || $schedule->start_time === null || $schedule->end_time === null) {
            return [];
        }

        return [[(string) $schedule->start_time, (string) $schedule->end_time]];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $staffRanges
     * @param  list<array{0: string, 1: string}>  $locationRanges
     * @return list<array{0: string, 1: string}>
     */
    private function intersectRanges(array $staffRanges, array $locationRanges): array
    {
        $intersections = [];

        foreach ($staffRanges as [$staffStart, $staffEnd]) {
            foreach ($locationRanges as [$locationStart, $locationEnd]) {
                $start = max($staffStart, $locationStart);
                $end = min($staffEnd, $locationEnd);

                if ($start < $end) {
                    $intersections[] = [$start, $end];
                }
            }
        }

        usort($intersections, static fn (array $left, array $right): int => strcmp($left[0], $right[0]));

        return $intersections;
    }

    /**
     * Локальный диапазон времени в интервал UTC.
     * В момент перехода на летнее время несуществующее время Carbon сдвигает вперёд.
     *
     * @param  array{0: string, 1: string}  $range
     */
    private function toInterval(string $day, array $range, string $timezone): TimeInterval
    {
        $start = CarbonImmutable::parse($day.' '.$range[0], $timezone)->utc();
        $end = CarbonImmutable::parse($day.' '.$range[1], $timezone)->utc();

        if ($end <= $start) {
            throw new InvalidArgumentException(
                "Диапазон {$range[0]}-{$range[1]} некорректен: конец должен быть позже начала в пределах одного дня.",
            );
        }

        return new TimeInterval($start, $end);
    }

    /**
     * Сортирует интервалы и склеивает пересекающиеся и соприкасающиеся.
     *
     * @param  list<TimeInterval>  $intervals
     * @return list<TimeInterval>
     */
    private function mergeIntervals(array $intervals): array
    {
        usort($intervals, fn (TimeInterval $a, TimeInterval $b): int => $a->start <=> $b->start);

        $merged = [];

        foreach ($intervals as $interval) {
            $last = end($merged);

            if ($last !== false && $interval->start <= $last->end) {
                $merged[array_key_last($merged)] = new TimeInterval(
                    $last->start,
                    $interval->end > $last->end ? $interval->end : $last->end,
                );

                continue;
            }

            $merged[] = $interval;
        }

        return array_values($merged);
    }

    /**
     * @param  list<TimeInterval>  $windows
     * @param  list<TimeInterval>  $cuts
     * @return list<TimeInterval>
     */
    private function subtractAll(array $windows, array $cuts): array
    {
        foreach ($cuts as $cut) {
            $next = [];

            foreach ($windows as $window) {
                array_push($next, ...$window->subtract($cut));
            }

            $windows = $next;
        }

        return $windows;
    }

    /**
     * @param  list<TimeInterval>  $others
     */
    private function overlapsAny(TimeInterval $interval, array $others): bool
    {
        foreach ($others as $other) {
            if ($interval->overlaps($other)) {
                return true;
            }
        }

        return false;
    }
}
