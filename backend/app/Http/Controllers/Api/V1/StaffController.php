<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StaffController
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->publicStaffQuery()->orderBy('display_name')->get(),
        ]);
    }

    public function manageIndex(): JsonResponse
    {
        return response()->json([
            'data' => Staff::query()
                ->with([
                    'location:id,name,timezone',
                    'services' => fn (Builder $query) => $query->orderBy('name'),
                ])
                ->orderBy('display_name')
                ->get(),
        ]);
    }

    public function show(Staff $staff): JsonResponse
    {
        abort_unless($staff->is_active, 404);

        return response()->json([
            'data' => $this->loadPublicRelations($staff),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedData($request);
        $serviceIds = $data['service_ids'] ?? [];
        unset($data['service_ids']);
        $data['is_active'] ??= true;

        $staff = DB::transaction(function () use ($data, $serviceIds): Staff {
            $staff = Staff::query()->create($data);
            $staff->services()->sync($serviceIds);

            return $staff;
        });

        return response()->json([
            'data' => $this->loadPublicRelations($staff),
        ], 201);
    }

    public function update(Request $request, Staff $staff): JsonResponse
    {
        $data = $this->validatedData($request, updating: true);
        $serviceIds = $data['service_ids'] ?? null;
        unset($data['service_ids']);

        DB::transaction(function () use ($data, $serviceIds, $staff): void {
            $staff->update($data);

            if ($serviceIds !== null) {
                $staff->services()->sync($serviceIds);
            }
        });

        return response()->json([
            'data' => $this->loadPublicRelations($staff->refresh()),
        ]);
    }

    public function destroy(Staff $staff): JsonResponse
    {
        $staff->update(['is_active' => false]);

        return response()->json(['data' => $staff->refresh()]);
    }

    private function publicStaffQuery(): Builder
    {
        return Staff::query()
            ->where('is_active', true)
            ->with([
                'location:id,name,timezone',
                'services' => fn (Builder $query) => $query
                    ->where('is_active', true)
                    ->orderBy('name'),
            ]);
    }

    private function loadPublicRelations(Staff $staff): Staff
    {
        return $staff->load([
            'location:id,name,timezone',
            'services' => fn (Builder $query) => $query
                ->where('is_active', true)
                ->orderBy('name'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';
        $tenantId = (int) $request->attributes->get('tenant')->getKey();

        return $request->validate([
            'location_id' => [
                $required,
                'integer',
                Rule::exists('locations', 'id')->where('tenant_id', $tenantId),
            ],
            'display_name' => [$required, 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'service_ids' => ['sometimes', 'array', 'max:100'],
            'service_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('services', 'id')
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true),
            ],
        ]);
    }
}
