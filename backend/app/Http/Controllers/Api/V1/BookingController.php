<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Booking\CancelBookingAction;
use App\Actions\Booking\CompleteBookingAction;
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

        $relations = ['service', 'staff.location', 'customer'];
        if ($membership->role !== Role::Staff) {
            $relations[] = 'changes.actor:id,name';
        }
        $query = Booking::query()->with($relations)->orderByDesc('starts_at');

        if ($membership->role === Role::Customer) {
            $query->where('customer_id', $request->user()->getAuthIdentifier());
        } elseif ($membership->role === Role::Staff) {
            $staffIds = Staff::query()
                ->where('user_id', $request->user()->getAuthIdentifier())
                ->where('is_active', true)
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
                CarbonImmutable::parse(
                    $filters['from'],
                    $request->attributes->get('tenant')->timezone,
                )->startOfDay()->utc(),
            );
        }

        if (isset($filters['to'])) {
            $query->where(
                'starts_at',
                '<',
                CarbonImmutable::parse(
                    $filters['to'],
                    $request->attributes->get('tenant')->timezone,
                )->addDay()->startOfDay()->utc(),
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

        return response()->json([
            'data' => $this->withRelations($booking, $this->membership($request)->role),
        ], 201);
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $membership = $this->authorizeBooking($request, $booking);

        return response()->json(['data' => $this->withRelations($booking, $membership->role)]);
    }

    public function confirm(
        Request $request,
        Booking $booking,
        ConfirmBookingAction $action,
    ): JsonResponse {
        $membership = $this->membership($request);

        return response()->json([
            'data' => $this->withRelations($action->execute($booking), $membership->role),
        ]);
    }

    public function complete(
        Request $request,
        Booking $booking,
        CompleteBookingAction $action,
    ): JsonResponse {
        $membership = $this->authorizeBooking($request, $booking);
        abort_unless(
            $membership->role->canManageTenant() || $membership->role === Role::Staff,
            403,
        );

        return response()->json([
            'data' => $this->withRelations($action->execute($booking), $membership->role),
        ]);
    }

    public function cancel(
        Request $request,
        Booking $booking,
        CancelBookingAction $action,
    ): JsonResponse {
        $membership = $this->authorizeBooking($request, $booking);
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $result = $action->execute(
            $booking,
            $membership->role === Role::Customer,
            (int) $request->user()->getAuthIdentifier(),
            $data['reason'] ?? null,
        );

        return response()->json([
            'data' => [
                'booking' => $this->withRelations($result['booking'], $membership->role),
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
        $membership = $this->authorizeBooking($request, $booking);
        $data = $request->validate([
            'starts_at' => $this->startsAtRules(),
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $booking = $action->execute(
            $booking,
            CarbonImmutable::parse($data['starts_at'])->utc(),
            (int) $request->user()->getAuthIdentifier(),
            $data['reason'] ?? null,
        );

        return response()->json(['data' => $this->withRelations($booking, $membership->role)]);
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
                ->where('is_active', true)
                ->exists()
        ) {
            return $membership;
        }

        abort(403);
    }

    private function withRelations(Booking $booking, Role $viewerRole): Booking
    {
        $relations = ['service', 'staff.location', 'customer'];
        if ($viewerRole !== Role::Staff) {
            $relations[] = 'changes.actor:id,name';
        }

        return $booking->load($relations);
    }
}
