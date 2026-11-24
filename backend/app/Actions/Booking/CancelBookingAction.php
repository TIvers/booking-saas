<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CancelBookingAction
{
    /**
     * @return array{booking: Booking, retained_deposit_cents: int, refund_due_cents: int}
     */
    public function execute(Booking $booking, bool $customerInitiated): array
    {
        return DB::transaction(function () use ($booking, $customerInitiated): array {
            $booking = Booking::query()
                ->lockForUpdate()
                ->findOrFail($booking->getKey());

            if (! in_array($booking->status, [BookingStatus::Pending, BookingStatus::Confirmed], true)) {
                abort(409, 'This booking can no longer be cancelled.');
            }

            $now = CarbonImmutable::now('UTC');

            if ($booking->starts_at->lessThanOrEqualTo($now)) {
                abort(409, 'A booking that has already started cannot be cancelled.');
            }

            $paidAmount = (int) $booking->payment()
                ->where('status', PaymentStatus::Succeeded->value)
                ->where('provider', '!=', 'demo')
                ->sum('amount_cents');

            $isLateCustomerCancellation = $customerInitiated
                && $booking->status === BookingStatus::Confirmed
                && $booking->starts_at->lessThan(
                    $now->addHours((int) config('booking.free_cancellation_hours')),
                );

            $retainedDeposit = $isLateCustomerCancellation
                ? min((int) $booking->service->deposit_cents, $paidAmount)
                : 0;

            $booking->status = BookingStatus::Cancelled;
            $booking->cancelled_at = $now;
            $booking->expires_at = null;
            $booking->save();
            Payment::query()
                ->where('booking_id', $booking->getKey())
                ->where('provider', 'demo')
                ->where('status', PaymentStatus::Pending->value)
                ->update(['status' => PaymentStatus::Failed->value, 'updated_at' => $now]);

            return [
                'booking' => $booking,
                'retained_deposit_cents' => $retainedDeposit,
                'refund_due_cents' => $paidAmount - $retainedDeposit,
            ];
        });
    }
}
