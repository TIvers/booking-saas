<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Models\Membership;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BookingSettingsController
{
    public function show(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        return response()->json(['data' => $this->settings($tenant)]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        $membership = Membership::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->firstOrFail();
        abort_unless($membership->role === Role::Owner, 403);

        $data = $request->validate([
            'allow_customer_rescheduling' => ['required', 'boolean'],
            'max_customer_reschedules' => ['nullable', 'integer', Rule::in([1, 2, 3])],
            'booking_lead_time_minutes' => ['required', 'integer', 'between:0,2147483647'],
        ]);

        $tenant->update($data);

        return response()->json(['data' => $this->settings($tenant->refresh())]);
    }

    /**
     * @return array{allow_customer_rescheduling: bool, max_customer_reschedules: ?int, booking_lead_time_minutes: int}
     */
    private function settings(Tenant $tenant): array
    {
        return [
            'allow_customer_rescheduling' => (bool) $tenant->allow_customer_rescheduling,
            'max_customer_reschedules' => $tenant->max_customer_reschedules,
            'booking_lead_time_minutes' => (int) $tenant->booking_lead_time_minutes,
        ];
    }
}
