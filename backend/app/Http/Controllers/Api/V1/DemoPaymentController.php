<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payment\CompleteDemoPaymentAction;
use App\Actions\Payment\CreateDemoPaymentAction;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DemoPaymentController
{
    public function store(
        Request $request,
        Booking $booking,
        CreateDemoPaymentAction $action,
    ): JsonResponse {
        $this->ensureDemoPaymentsEnabled();
        $this->authorizeCustomerBooking($request, $booking);
        $payment = $action->execute($booking);

        return response()->json(['data' => $this->paymentPayload($payment)]);
    }

    public function complete(
        Request $request,
        Booking $booking,
        CompleteDemoPaymentAction $action,
    ): JsonResponse {
        $this->ensureDemoPaymentsEnabled();
        $this->authorizeCustomerBooking($request, $booking);
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['succeeded', 'failed'])],
        ]);
        $result = $action->execute($booking, $data['outcome']);

        return response()->json([
            'data' => [
                'payment' => $this->paymentPayload($result['payment']),
                'booking' => $result['booking']->load(['service', 'staff.location', 'customer']),
            ],
        ]);
    }

    private function ensureDemoPaymentsEnabled(): void
    {
        if (! config('payments.demo_enabled')) {
            abort(404);
        }
    }

    private function authorizeCustomerBooking(Request $request, Booking $booking): void
    {
        if ((int) $booking->customer_id !== (int) $request->user()->getAuthIdentifier()) {
            abort(403);
        }
    }

    /**
     * @return array{id: int, status: string, amount_cents: int, currency: string}
     */
    private function paymentPayload(Payment $payment): array
    {
        return [
            'id' => (int) $payment->getKey(),
            'status' => $payment->status->value,
            'amount_cents' => $payment->amount_cents,
            'currency' => $payment->currency,
        ];
    }
}
