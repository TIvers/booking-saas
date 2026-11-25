<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\SlotAlreadyTakenException;
use App\Jobs\SendTelegramNotification;
use App\Jobs\ReleaseExpiredReservations;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Services\Slots\Slot;
use App\Services\Slots\SlotGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateBookingAction
{
    public function execute(
        User $customer,
        Service $service,
        Staff $staff,
        CarbonImmutable $startsAt,
    ): Booking {
        try {
            return DB::transaction(function () use ($customer, $service, $staff, $startsAt): Booking {
                $now = CarbonImmutable::now('UTC');
                $service = Service::query()->findOrFail($service->getKey());
                $staff = Staff::query()->with('location')->findOrFail($staff->getKey());

                if (! $service->is_active || ! $staff->is_active) {
                    throw ValidationException::withMessages([
                        'service_id' => ['The selected service or staff member is not active.'],
                    ]);
                }

                if (! $staff->services()->where('services.id', $service->getKey())->exists()) {
                    throw ValidationException::withMessages([
                        'service_id' => ['The selected staff member does not provide this service.'],
                    ]);
                }

                $this->cancelExpiredReservations($staff, $now);

                $localDate = $startsAt->setTimezone($staff->location->timezone)->startOfDay();
                $slot = $this->findSlot($staff, $service, $localDate, $startsAt, $now);

                if ($slot === null) {
                    throw new SlotAlreadyTakenException;
                }

                $requiresDeposit = $service->deposit_cents > 0;
                $expiresAt = $requiresDeposit
                    ? $now->addMinutes((int) config('booking.reservation_ttl_minutes'))
                    : null;

                $booking = Booking::query()->create([
                    'staff_id' => $staff->getKey(),
                    'service_id' => $service->getKey(),
                    'customer_id' => $customer->getAuthIdentifier(),
                    'starts_at' => $slot->startsAt,
                    'ends_at' => $slot->endsAt,
                    'status' => $requiresDeposit ? BookingStatus::Pending : BookingStatus::Confirmed,
                    'expires_at' => $expiresAt,
                ]);

                $tenantId = (int) app('currentTenant')->getKey();
                SendTelegramNotification::dispatch(
                    (int) $booking->getKey(),
                    $tenantId,
                    'booking_created',
                )->afterCommit();

                if (! $requiresDeposit) {
                    app(ScheduleTelegramReminders::class)->execute($booking);
                }

                if ($expiresAt !== null) {
                    ReleaseExpiredReservations::dispatch(
                        (int) $booking->getKey(),
                        (int) app('currentTenant')->getKey(),
                    )->delay($expiresAt)->afterCommit();
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

        foreach ($generator->forDay($staff, $service, $localDate, $now) as $slot) {
            if ($slot->startsAt->utc()->equalTo($startsAt->utc())) {
                return $slot;
            }
        }

        return null;
    }

    private function cancelExpiredReservations(Staff $staff, CarbonImmutable $now): void
    {
        $expiredBookings = Booking::query()
            ->where('staff_id', $staff->getKey())
            ->where('status', BookingStatus::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now);
        $expiredBookingIds = (clone $expiredBookings)->pluck('id');

        if ($expiredBookingIds->isEmpty()) {
            return;
        }

        Payment::query()
            ->whereIn('booking_id', $expiredBookingIds)
            ->where('provider', 'demo')
            ->where('status', PaymentStatus::Pending->value)
            ->update([
                'status' => PaymentStatus::Failed->value,
                'updated_at' => $now,
            ]);

        $expiredBookings->update([
            'status' => BookingStatus::Cancelled->value,
            'cancelled_at' => $now,
            'expires_at' => null,
            'updated_at' => $now,
        ]);

        foreach ($expiredBookingIds as $bookingId) {
            SendTelegramNotification::dispatch(
                (int) $bookingId,
                (int) app('currentTenant')->getKey(),
                'booking_cancelled',
            )->afterCommit();
        }
    }
}
