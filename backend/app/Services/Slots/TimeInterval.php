<?php

declare(strict_types=1);

namespace App\Services\Slots;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Неизменяемый полуоткрытый интервал времени [start, end).
 *
 * Полуоткрытость важна: интервалы 10:00-11:00 и 11:00-12:00 не пересекаются,
 * что совпадает с поведением tstzrange(..., '[)') в exclusion constraint.
 */
final readonly class TimeInterval
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {
        if ($end <= $start) {
            throw new InvalidArgumentException('Конец интервала должен быть позже его начала.');
        }
    }

    public function durationInMinutes(): int
    {
        return (int) $this->start->diffInMinutes($this->end);
    }

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }

    /** Целиком ли $other лежит внутри текущего интервала. */
    public function contains(self $other): bool
    {
        return $this->start <= $other->start && $other->end <= $this->end;
    }

    /**
     * Вычитание интервала. Возвращает 0, 1 или 2 интервала.
     *
     * @return list<TimeInterval>
     */
    public function subtract(self $other): array
    {
        if (! $this->overlaps($other)) {
            return [$this];
        }

        $result = [];

        if ($this->start < $other->start) {
            $result[] = new self($this->start, $other->start);
        }

        if ($other->end < $this->end) {
            $result[] = new self($other->end, $this->end);
        }

        return $result;
    }
}
