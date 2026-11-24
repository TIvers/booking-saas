<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\ScheduleException;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ScheduleController
{
    public function show(Staff $staff): JsonResponse
    {
        return $this->scheduleResponse($staff);
    }

    public function showMine(Request $request): JsonResponse
    {
        $staff = Staff::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->firstOrFail();

        return $this->scheduleResponse($staff);
    }

    private function scheduleResponse(Staff $staff): JsonResponse
    {
        $staff->loadMissing('location:id,name,timezone');

        return response()->json([
            'data' => [
                'staff_id' => $staff->getKey(),
                'staff' => [
                    'display_name' => $staff->display_name,
                    'location' => [
                        'name' => $staff->location->name,
                        'timezone' => $staff->location->timezone,
                    ],
                ],
                'work_schedules' => $staff->workSchedules()
                    ->orderBy('weekday')
                    ->orderBy('start_time')
                    ->get(['id', 'weekday', 'start_time', 'end_time']),
                'breaks' => $staff->scheduleBreaks()
                    ->orderBy('weekday')
                    ->orderBy('start_time')
                    ->get(['id', 'weekday', 'start_time', 'end_time']),
                'exceptions' => $staff->scheduleExceptions()
                    ->orderBy('date')
                    ->get(['id', 'date', 'is_day_off', 'start_time', 'end_time']),
            ],
        ]);
    }

    public function update(Request $request, Staff $staff): JsonResponse
    {
        $data = $request->validate([
            'work_schedules' => ['present', 'array', 'max:56'],
            'work_schedules.*.weekday' => ['required', 'integer', 'between:1,7'],
            'work_schedules.*.start_time' => ['required', 'date_format:H:i'],
            'work_schedules.*.end_time' => ['required', 'date_format:H:i', 'after:work_schedules.*.start_time'],
            'breaks' => ['present', 'array', 'max:56'],
            'breaks.*.weekday' => ['required', 'integer', 'between:1,7'],
            'breaks.*.start_time' => ['required', 'date_format:H:i'],
            'breaks.*.end_time' => ['required', 'date_format:H:i', 'after:breaks.*.start_time'],
        ]);

        $this->ensureNoOverlaps($data['work_schedules'], 'work_schedules');
        $this->ensureNoOverlaps($data['breaks'], 'breaks');
        $this->ensureBreaksFitWorkHours($data['breaks'], $data['work_schedules']);

        DB::transaction(function () use ($data, $staff): void {
            $staff->workSchedules()->delete();
            $staff->scheduleBreaks()->delete();

            foreach ($data['work_schedules'] as $schedule) {
                $staff->workSchedules()->create($schedule);
            }

            foreach ($data['breaks'] as $break) {
                $staff->scheduleBreaks()->create($break);
            }
        });

        return $this->show($staff);
    }

    public function updateException(Request $request, Staff $staff, string $date): JsonResponse
    {
        validator(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();

        $data = $request->validate([
            'is_day_off' => ['required', 'boolean'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'],
        ]);

        if (! $request->boolean('is_day_off') && (! isset($data['start_time']) || ! isset($data['end_time']))) {
            throw ValidationException::withMessages([
                'start_time' => ['Working hours are required unless the date is marked as a day off.'],
                'end_time' => ['Working hours are required unless the date is marked as a day off.'],
            ]);
        }

        if ($request->boolean('is_day_off')) {
            $data['start_time'] = null;
            $data['end_time'] = null;
        }

        $exception = ScheduleException::query()->updateOrCreate(
            [
                'staff_id' => $staff->getKey(),
                'date' => $date,
            ],
            $data,
        );

        return response()->json(['data' => $exception]);
    }

    public function destroyException(Staff $staff, string $date): Response
    {
        validator(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();

        $exception = $staff->scheduleExceptions()
            ->whereDate('date', $date)
            ->firstOrFail();
        $exception->delete();

        return response()->noContent();
    }

    /**
     * @param  list<array{weekday: int, start_time: string, end_time: string}>  $ranges
     */
    private function ensureNoOverlaps(array $ranges, string $field): void
    {
        $byWeekday = [];

        foreach ($ranges as $range) {
            $byWeekday[$range['weekday']][] = $range;
        }

        foreach ($byWeekday as $weekdayRanges) {
            usort($weekdayRanges, fn (array $a, array $b): int => strcmp($a['start_time'], $b['start_time']));

            for ($index = 1; $index < count($weekdayRanges); $index++) {
                if ($weekdayRanges[$index]['start_time'] < $weekdayRanges[$index - 1]['end_time']) {
                    throw ValidationException::withMessages([
                        $field => ['Time ranges for the same weekday must not overlap.'],
                    ]);
                }
            }
        }
    }

    /**
     * @param  list<array{weekday: int, start_time: string, end_time: string}>  $breaks
     * @param  list<array{weekday: int, start_time: string, end_time: string}>  $workSchedules
     */
    private function ensureBreaksFitWorkHours(array $breaks, array $workSchedules): void
    {
        foreach ($breaks as $break) {
            $fitsWorkWindow = false;

            foreach ($workSchedules as $schedule) {
                if (
                    (int) $schedule['weekday'] === (int) $break['weekday']
                    && $break['start_time'] >= $schedule['start_time']
                    && $break['end_time'] <= $schedule['end_time']
                ) {
                    $fitsWorkWindow = true;
                    break;
                }
            }

            if (! $fitsWorkWindow) {
                throw ValidationException::withMessages([
                    'breaks' => ['Each break must fit inside a work schedule for the same weekday.'],
                ]);
            }
        }
    }
}
