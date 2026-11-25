<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Location;
use App\Models\LocationWorkSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LocationController
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Location::query()->orderBy('name')->get(),
        ]);
    }

    public function show(Location $location): JsonResponse
    {
        return response()->json(['data' => $location]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'timezone' => ['sometimes', 'required', 'timezone', 'max:64'],
        ]);
        $data['timezone'] ??= 'UTC';

        $location = DB::transaction(function () use ($data): Location {
            $location = Location::query()->create($data);
            $location->workSchedules()->createMany(LocationWorkSchedule::defaultWeek());

            return $location;
        });

        return response()->json(['data' => $location], 201);
    }

    public function update(Request $request, Location $location): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'timezone' => ['sometimes', 'required', 'timezone', 'max:64'],
        ]);

        $location->update($data);

        return response()->json(['data' => $location->refresh()]);
    }
}
