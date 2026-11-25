<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;

class CompleteBookingAction
{
    public function execute(Booking $booking): Booking
    {
        return DB::transaction(function () use ($booking): Booking {
            $booking = Booking::query()
                ->lockForUpdate()
                ->findOrFail($booking->getKey());

            if ($booking->status !== BookingStatus::Confirmed) {
                abort(409, 'Only a confirmed booking can be completed.');
            }

            if ($booking->starts_at->isFuture()) {
                abort(409, 'A booking cannot be completed before it starts.');
            }

            $booking->status = BookingStatus::Completed;
            $booking->save();

            return $booking;
        });
    }
}
