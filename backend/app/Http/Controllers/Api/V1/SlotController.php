<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\Staff;
use App\Services\Slots\SlotGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SlotController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) $request->attributes->get('tenant')->getKey();
        $data = $request->validate([
            'staff_id' => [
                'required',
                'integer',
                Rule::exists('staff', 'id')
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true),
            ],
            'service_id' => [
                'required',
                'integer',
                Rule::exists('services', 'id')
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true),
            ],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $staff = Staff::query()->with('location')->findOrFail($data['staff_id']);
        $service = Service::query()->findOrFail($data['service_id']);

        if (! $staff->services()->where('services.id', $service->getKey())->exists()) {
            throw ValidationException::withMessages([
                'service_id' => ['The selected staff member does not provide this service.'],
            ]);
        }

        $date = CarbonImmutable::parse($data['date'], $staff->location->timezone);
        $generator = new SlotGenerator(
            stepMinutes: (int) config('booking.slot_step_minutes'),
            minLeadMinutes: (int) config('booking.min_lead_minutes'),
        );

        return response()->json([
            'data' => array_map(
                static fn ($slot): array => $slot->toArray(),
                $generator->forDay($staff, $service, $date),
            ),
        ]);
    }
}
