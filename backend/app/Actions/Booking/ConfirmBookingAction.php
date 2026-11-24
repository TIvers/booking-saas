<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
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

            return $booking;
        });
    }
}
