<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateDemoPaymentAction
{
    public function execute(Booking $booking): Payment
    {
        return DB::transaction(function () use ($booking): Payment {
            $booking = Booking::query()
                ->with('service')
                ->lockForUpdate()
                ->findOrFail($booking->getKey());

            if ($booking->status !== BookingStatus::Pending) {
                abort(409, 'Only a pending booking can be paid.');
            }

            if ($booking->expires_at === null || $booking->expires_at->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
                abort(410, 'The booking reservation has expired.');
            }

            $amountCents = (int) $booking->service->deposit_cents;

            if ($amountCents <= 0) {
                abort(422, 'This service does not require a deposit.');
            }

            $payment = Payment::query()
                ->where('booking_id', $booking->getKey())
                ->where('provider', 'demo')
                ->lockForUpdate()
                ->first();

            if ($payment !== null) {
                if ($payment->status === PaymentStatus::Pending) {
                    return $payment;
                }

                abort(409, 'A demo payment has already been completed.');
            }

            return Payment::query()->create([
                'booking_id' => $booking->getKey(),
                'provider' => 'demo',
                'provider_payment_id' => 'demo-'.Str::uuid(),
                'status' => PaymentStatus::Pending,
                'amount_cents' => $amountCents,
                'currency' => app('currentTenant')->currency,
            ]);
        });
    }
}
