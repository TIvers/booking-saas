<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ReleaseExpiredReservations implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly int $bookingId,
        public readonly int $tenantId,
    ) {}

    public function handle(): void
    {
        $hadTenant = app()->bound('currentTenant');
        $previousTenant = $hadTenant ? app('currentTenant') : null;

        try {
            DB::transaction(function (): void {
                DB::select(
                    "SELECT set_config('app.tenant_id', ?, true)",
                    [(string) $this->tenantId],
                );

                $tenant = Tenant::query()->find($this->tenantId);

                if ($tenant === null) {
                    return;
                }

                app()->instance('currentTenant', $tenant);

                $booking = Booking::query()
                    ->whereKey($this->bookingId)
                    ->lockForUpdate()
                    ->first();

                if (
                    $booking === null
                    || $booking->status !== BookingStatus::Pending
                    || $booking->expires_at === null
                    || $booking->expires_at->isFuture()
                ) {
                    return;
                }

                $booking->status = BookingStatus::Cancelled;
                $booking->cancelled_at = now();
                $booking->expires_at = null;
                $booking->save();
            });
        } finally {
            if ($hadTenant) {
                app()->instance('currentTenant', $previousTenant);
            } else {
                app()->forgetInstance('currentTenant');
            }
        }
    }
}
