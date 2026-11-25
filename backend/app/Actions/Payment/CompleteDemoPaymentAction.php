<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Actions\Booking\ScheduleTelegramReminders;
use App\Jobs\SendTelegramNotification;
use App\Models\Booking;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CompleteDemoPaymentAction
{
    /**
     * @return array{booking: Booking, payment: Payment}
     */
    public function execute(Booking $booking, string $outcome): array
    {
        return DB::transaction(function () use ($booking, $outcome): array {
            $booking = Booking::query()
                ->lockForUpdate()
                ->findOrFail($booking->getKey());
            $payment = Payment::query()
                ->where('booking_id', $booking->getKey())
                ->where('provider', 'demo')
                ->lockForUpdate()
                ->firstOrFail();

            if ($payment->status !== PaymentStatus::Pending) {
                if ($payment->status->value === $outcome) {
                    return compact('booking', 'payment');
                }

                abort(409, 'The demo payment has already been completed.');
            }

            if ($booking->status !== BookingStatus::Pending) {
                abort(409, 'The booking is no longer awaiting payment.');
            }

            $now = CarbonImmutable::now('UTC');

            if ($booking->expires_at === null || $booking->expires_at->lessThanOrEqualTo($now)) {
                abort(410, 'The booking reservation has expired.');
            }

            if ($outcome === PaymentStatus::Succeeded->value) {
                $payment->status = PaymentStatus::Succeeded;
                $booking->status = BookingStatus::Confirmed;
            } else {
                $payment->status = PaymentStatus::Failed;
                $booking->status = BookingStatus::Cancelled;
                $booking->cancelled_at = $now;
            }

            $payment->save();
            $booking->expires_at = null;
            $booking->save();
            if ($outcome === PaymentStatus::Failed->value) {
                SendTelegramNotification::dispatch(
                    (int) $booking->getKey(),
                    (int) app('currentTenant')->getKey(),
                    'booking_cancelled',
                )->afterCommit();
            } else {
                SendTelegramNotification::dispatch(
                    (int) $booking->getKey(),
                    (int) app('currentTenant')->getKey(),
                    'booking_confirmed',
                )->afterCommit();
                app(ScheduleTelegramReminders::class)->execute($booking);
            }

            return compact('booking', 'payment');
        });
    }
}
