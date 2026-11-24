<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Booking\CancelBookingAction;
use App\Actions\Booking\ConfirmBookingAction;
use App\Actions\Booking\CreateBookingAction;
use App\Actions\Booking\RescheduleBookingAction;
use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Models\Booking;
use App\Models\Membership;
use App\Models\Service;
use App\Models\Staff;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BookingController
{
    public function index(Request $request): JsonResponse
    {
        $membership = $this->membership($request);
        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(BookingStatus::class)],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        if (isset($filters['from'], $filters['to']) && $filters['to'] < $filters['from']) {
            throw ValidationException::withMessages([
                'to' => ['The end date must be on or after the start date.'],
            ]);
        }

        $query = Booking::query()
            ->with(['service', 'staff.location', 'customer'])
            ->orderByDesc('starts_at');

        if ($membership->role === Role::Customer) {
            $query->where('customer_id', $request->user()->getAuthIdentifier());
        } elseif ($membership->role === Role::Staff) {
            $staffIds = Staff::query()
                ->where('user_id', $request->user()->getAuthIdentifier())
                ->pluck('id');
            $query->whereIn('staff_id', $staffIds);
        } elseif (! $membership->role->canManageTenant()) {
            abort(403);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['from'])) {
            $query->where(
                'starts_at',
                '>=',
                CarbonImmutable::parse($filters['from'], 'UTC')->startOfDay(),
            );
        }

        if (isset($filters['to'])) {
            $query->where(
                'starts_at',
                '<',
                CarbonImmutable::parse($filters['to'], 'UTC')->addDay()->startOfDay(),
            );
        }

        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(Request $request, CreateBookingAction $action): JsonResponse
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
            'starts_at' => $this->startsAtRules(),
        ]);

        $booking = $action->execute(
            $request->user(),
            Service::query()->findOrFail($data['service_id']),
            Staff::query()->findOrFail($data['staff_id']),
            CarbonImmutable::parse($data['starts_at'])->utc(),
        );

        return response()->json(['data' => $this->withRelations($booking)], 201);
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $this->authorizeBooking($request, $booking);

        return response()->json(['data' => $this->withRelations($booking)]);
    }

    public function confirm(Booking $booking, ConfirmBookingAction $action): JsonResponse
    {
        return response()->json([
            'data' => $this->withRelations($action->execute($booking)),
        ]);
    }

    public function cancel(
        Request $request,
        Booking $booking,
        CancelBookingAction $action,
    ): JsonResponse {
        $membership = $this->authorizeBooking($request, $booking);
        $result = $action->execute($booking, $membership->role === Role::Customer);

        return response()->json([
            'data' => [
                'booking' => $this->withRelations($result['booking']),
                'cancellation' => [
                    'retained_deposit_cents' => $result['retained_deposit_cents'],
                    'refund_due_cents' => $result['refund_due_cents'],
                ],
            ],
        ]);
    }

    public function reschedule(
        Request $request,
        Booking $booking,
        RescheduleBookingAction $action,
    ): JsonResponse {
        $this->authorizeBooking($request, $booking);
        $data = $request->validate([
            'starts_at' => $this->startsAtRules(),
        ]);

        $booking = $action->execute(
            $booking,
            CarbonImmutable::parse($data['starts_at'])->utc(),
        );

        return response()->json(['data' => $this->withRelations($booking)]);
    }

    /**
     * @return list<mixed>
     */
    private function startsAtRules(): array
    {
        return [
            'required',
            'date',
            function (string $attribute, mixed $value, callable $fail): void {
                if (! is_string($value) || preg_match('/(?:Z|[+-]\d{2}:\d{2})$/i', $value) !== 1) {
                    $fail('The start time must include a UTC offset.');
                }
            },
        ];
    }

    private function membership(Request $request): Membership
    {
        return Membership::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->firstOrFail();
    }

    private function authorizeBooking(Request $request, Booking $booking): Membership
    {
        $membership = $this->membership($request);

        if ($membership->role->canManageTenant()) {
            return $membership;
        }

        if (
            $membership->role === Role::Customer
            && (int) $booking->customer_id === (int) $request->user()->getAuthIdentifier()
        ) {
            return $membership;
        }

        if (
            $membership->role === Role::Staff
            && Staff::query()
                ->where('id', $booking->staff_id)
                ->where('user_id', $request->user()->getAuthIdentifier())
                ->exists()
        ) {
            return $membership;
        }

        abort(403);
    }

    private function withRelations(Booking $booking): Booking
    {
        return $booking->load(['service', 'staff.location', 'customer']);
    }
}
