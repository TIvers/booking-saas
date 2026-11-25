<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ServiceController
{
    private const MAX_CENTS = 9000000000000000;

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Service::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function manageIndex(): JsonResponse
    {
        return response()->json([
            'data' => Service::query()->orderBy('name')->get(),
        ]);
    }

    public function show(Service $service): JsonResponse
    {
        abort_unless($service->is_active, 404);

        return response()->json(['data' => $service]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $this->ensureDepositWithinPrice($data);

        $data['buffer_min'] ??= 0;
        $data['deposit_cents'] ??= 0;
        $data['is_active'] ??= true;

        $service = Service::query()->create($data);

        return response()->json(['data' => $service], 201);
    }

    public function update(Request $request, Service $service): JsonResponse
    {
        $data = $request->validate($this->rules(updating: true));
        $this->ensureDepositWithinPrice($data, $service);

        $service->update($data);

        return response()->json(['data' => $service->refresh()]);
    }

    public function destroy(Service $service): JsonResponse
    {
        $service->update(['is_active' => false]);

        return response()->json(['data' => $service->refresh()]);
    }

    private function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'duration_min' => [$required, 'integer', 'between:1,1440'],
            'buffer_min' => ['sometimes', 'integer', 'between:0,1440'],
            'price_cents' => [$required, 'integer', 'between:0,'.self::MAX_CENTS],
            'deposit_cents' => ['sometimes', 'integer', 'between:0,'.self::MAX_CENTS],
            'is_active' => ['sometimes', 'boolean'],
            'booking_lead_time_minutes' => ['sometimes', 'nullable', 'integer', 'between:0,2147483647'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function ensureDepositWithinPrice(array $data, ?Service $service = null): void
    {
        $price = (int) ($data['price_cents'] ?? $service?->price_cents);
        $deposit = (int) ($data['deposit_cents'] ?? $service?->deposit_cents ?? 0);

        if ($deposit > $price) {
            throw ValidationException::withMessages([
                'deposit_cents' => ['The deposit cannot exceed the service price.'],
            ]);
        }
    }
}
