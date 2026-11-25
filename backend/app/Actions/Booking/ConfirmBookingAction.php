<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Jobs\SendTelegramNotification;
use App\Models\Booking;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ConfirmBookingAction
{
    public function execute(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking): Booking {
            $booking = Booking::query()
                ->lockForUpdate()
                ->findOrFail($booking->getKey());

            if ($booking->status !== BookingStatus::Pending) {
                abort(409, 'Only a pending booking can be confirmed.');
            }

            if ($booking->expires_at !== null && $booking->expires_at->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
                abort(410, 'The booking reservation has expired.');
            }

            $booking->status = BookingStatus::Confirmed;
            $booking->expires_at = null;
            $booking->save();
            Payment::query()
                ->where('booking_id', $booking->getKey())
                ->where('provider', 'demo')
                ->where('status', PaymentStatus::Pending->value)
                ->update(['status' => PaymentStatus::Failed->value, 'updated_at' => now()]);

            $tenantId = (int) app('currentTenant')->getKey();
            SendTelegramNotification::dispatch(
                (int) $booking->getKey(),
                $tenantId,
                'booking_confirmed',
            )->afterCommit();
            app(ScheduleTelegramReminders::class)->execute($booking);

            return $booking;
        });
    }
}
