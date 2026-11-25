<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Location;
use App\Models\LocationScheduleClosure;
use App\Models\LocationWorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class LocationScheduleController
{
    public function show(Location $location): JsonResponse
    {
        $workSchedules = $location->workSchedules()
            ->orderBy('weekday')
            ->get(['weekday', 'is_closed', 'start_time', 'end_time'])
            ->toArray();

        if ($workSchedules === []) {
            $workSchedules = LocationWorkSchedule::defaultWeek();
        }

        $today = CarbonImmutable::now($location->timezone)->toDateString();
        $closures = $location->scheduleClosures()
            ->whereDate('date', '>=', $today)
            ->orderBy('date')
            ->limit(100)
            ->get(['date', 'reason'])
            ->map(static fn (LocationScheduleClosure $closure): array => [
                'date' => $closure->date->toDateString(),
                'reason' => $closure->reason,
            ]);

        return response()->json([
            'data' => [
                'location' => [
                    'id' => $location->getKey(),
                    'name' => $location->name,
                    'timezone' => $location->timezone,
                ],
                'work_schedules' => $workSchedules,
                'closures' => $closures,
            ],
        ]);
    }

    public function update(Request $request, Location $location): JsonResponse
    {
        $data = $request->validate([
            'work_schedules' => ['required', 'array', 'size:7'],
            'work_schedules.*.weekday' => ['required', 'integer', 'between:1,7', 'distinct'],
            'work_schedules.*.is_closed' => ['required', 'boolean'],
            'work_schedules.*.start_time' => ['nullable', 'date_format:H:i'],
            'work_schedules.*.end_time' => ['nullable', 'date_format:H:i'],
        ]);

        foreach ($data['work_schedules'] as $index => $schedule) {
            if ($schedule['is_closed']) {
                $data['work_schedules'][$index]['start_time'] = null;
                $data['work_schedules'][$index]['end_time'] = null;

                continue;
            }

            if (
                ! isset($schedule['start_time'], $schedule['end_time'])
                || $schedule['end_time'] <= $schedule['start_time']
            ) {
                throw ValidationException::withMessages([
                    "work_schedules.{$index}.end_time" => [
                        'An open day requires an end time later than its start time.',
                    ],
                ]);
            }
        }

        DB::transaction(function () use ($data, $location): void {
            $location->workSchedules()->delete();
            $location->workSchedules()->createMany($data['work_schedules']);
        });

        return $this->show($location);
    }

    public function storeClosure(Request $request, Location $location): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $today = CarbonImmutable::now($location->timezone)->toDateString();

        if ($data['date'] < $today) {
            throw ValidationException::withMessages([
                'date' => ['A closure date cannot be in the past.'],
            ]);
        }

        $closure = $location->scheduleClosures()->updateOrCreate(
            ['date' => $data['date']],
            ['reason' => trim($data['reason'])],
        );

        return response()->json([
            'data' => [
                'date' => $closure->date->toDateString(),
                'reason' => $closure->reason,
            ],
        ], Response::HTTP_CREATED);
    }

    public function destroyClosure(Location $location, string $date): Response
    {
        validator(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();

        $location->scheduleClosures()
            ->whereDate('date', $date)
            ->firstOrFail()
            ->delete();

        return response()->noContent();
    }
}
