<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Exceptions\SlotAlreadyTakenException;
use App\Jobs\ReleaseExpiredReservations;
use App\Jobs\SendTelegramNotification;
use App\Models\Booking;
use App\Models\BookingChange;
use App\Models\Service;
use App\Models\Staff;
use App\Services\Slots\Slot;
use App\Services\Slots\SlotGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class RescheduleBookingAction
{
    public function execute(
        Booking $booking,
        CarbonImmutable $startsAt,
        ?int $actorUserId = null,
        ?string $reason = null,
        ?Role $actorRole = null,
    ): Booking
    {
        try {
            return DB::transaction(function () use ($booking, $startsAt, $actorUserId, $reason, $actorRole): Booking {
                $booking = Booking::query()
                    ->lockForUpdate()
                    ->findOrFail($booking->getKey());

                if (! in_array($booking->status, [BookingStatus::Pending, BookingStatus::Confirmed], true)) {
                    abort(409, 'This booking can no longer be rescheduled.');
                }

                $now = CarbonImmutable::now('UTC');

                if ($booking->starts_at->lessThanOrEqualTo($now)) {
                    abort(409, 'A booking that has already started cannot be rescheduled.');
                }

                if (
                    $booking->status === BookingStatus::Pending
                    && $booking->expires_at !== null
                    && $booking->expires_at->lessThanOrEqualTo($now)
                ) {
                    abort(410, 'The booking reservation has expired.');
                }

                $staff = Staff::query()->with('location')->findOrFail($booking->staff_id);
                $service = Service::query()->findOrFail($booking->service_id);

                if (! $staff->is_active || ! $service->is_active) {
                    abort(409, 'The booking service or staff member is no longer active.');
                }

                if (! $staff->services()->where('services.id', $service->getKey())->exists()) {
                    abort(409, 'The staff member no longer provides this service.');
                }

                if ($booking->starts_at->equalTo($startsAt)) {
                    return $booking;
                }

                if ($actorRole === Role::Customer) {
                    $tenant = app('currentTenant');
                    if (! $tenant->allow_customer_rescheduling) {
                        abort(403, 'Customer rescheduling is disabled.');
                    }

                    $maximumReschedules = $tenant->max_customer_reschedules;
                    if (
                        $maximumReschedules !== null
                        && $booking->changes()->where('action', 'rescheduled')->count() >= $maximumReschedules
                    ) {
                        abort(409, 'The customer rescheduling limit has been reached.');
                    }
                }

                $previousStartsAt = $booking->starts_at;
                $this->cancelExpiredReservations($staff, $booking, $now);

                $localDate = $startsAt->setTimezone($staff->location->timezone)->startOfDay();
                $slot = $this->findSlot($staff, $service, $localDate, $startsAt, $now, $booking);

                if ($slot === null) {
                    throw new SlotAlreadyTakenException;
                }

                $booking->starts_at = $slot->startsAt;
                $booking->ends_at = $slot->endsAt;

                if ($booking->status === BookingStatus::Pending) {
                    $booking->expires_at = $now->addMinutes((int) config('booking.reservation_ttl_minutes'));
                }

                $booking->save();
                BookingChange::query()->create([
                    'booking_id' => $booking->getKey(),
                    'actor_user_id' => $actorUserId,
                    'action' => 'rescheduled',
                    'from_starts_at' => $previousStartsAt,
                    'to_starts_at' => $booking->starts_at,
                    'reason' => $reason,
                ]);

                $tenantId = (int) app('currentTenant')->getKey();
                SendTelegramNotification::dispatch(
                    (int) $booking->getKey(),
                    $tenantId,
                    'booking_rescheduled',
                )->afterCommit();
                if ($booking->status === BookingStatus::Confirmed) {
                    app(ScheduleTelegramReminders::class)->execute($booking);
                }

                if ($booking->status === BookingStatus::Pending && $booking->expires_at !== null) {
                    ReleaseExpiredReservations::dispatch(
                        (int) $booking->getKey(),
                        (int) app('currentTenant')->getKey(),
                    )->delay($booking->expires_at)->afterCommit();
                }

                return $booking;
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23P01') {
                throw $exception;
            }

            throw new SlotAlreadyTakenException(previous: $exception);
        }
    }

    private function findSlot(
        Staff $staff,
        Service $service,
        CarbonImmutable $localDate,
        CarbonImmutable $startsAt,
        CarbonImmutable $now,
        Booking $booking,
    ): ?Slot {
        $tenant = app('currentTenant');
        $generator = new SlotGenerator(
            stepMinutes: (int) config('booking.slot_step_minutes'),
            minLeadMinutes: (int) (
                $service->booking_lead_time_minutes
                ?? $tenant->booking_lead_time_minutes
                ?? config('booking.min_lead_minutes')
            ),
        );

        foreach ($generator->forDay(
            $staff,
            $service,
            $localDate,
            $now,
            (int) $booking->getKey(),
        ) as $slot) {
            if ($slot->startsAt->utc()->equalTo($startsAt->utc())) {
                return $slot;
            }
        }

        return null;
    }

    private function cancelExpiredReservations(Staff $staff, Booking $except, CarbonImmutable $now): void
    {
        $expiredBookings = Booking::query()
            ->where('staff_id', $staff->getKey())
            ->where('id', '<>', $except->getKey())
            ->where('status', BookingStatus::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now);
        $expiredIds = (clone $expiredBookings)->pluck('id');

        if ($expiredIds->isEmpty()) {
            return;
        }

        $expiredBookings->update([
            'status' => BookingStatus::Cancelled->value,
            'cancelled_at' => $now,
            'expires_at' => null,
            'updated_at' => $now,
        ]);
        foreach ($expiredIds as $bookingId) {
            SendTelegramNotification::dispatch(
                (int) $bookingId,
                (int) app('currentTenant')->getKey(),
                'booking_cancelled',
            )->afterCommit();
        }
    }
}
