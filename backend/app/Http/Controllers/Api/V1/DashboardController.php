<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        $periodStart = CarbonImmutable::now($tenant->timezone)->startOfMonth();
        $periodEnd = $periodStart->addMonth();

        $bookings = Booking::query()
            ->with([
                'service:id,name,price_cents',
                'staff:id,display_name',
            ])
            ->where('starts_at', '>=', $periodStart->utc())
            ->where('starts_at', '<', $periodEnd->utc())
            ->get();

        $statusCounts = array_fill_keys(
            array_map(static fn (BookingStatus $status): string => $status->value, BookingStatus::cases()),
            0,
        );
        $bookedValueCents = 0;
        $services = [];
        $staffActivity = [];

        foreach ($bookings as $booking) {
            $status = $booking->status->value;
            $statusCounts[$status]++;

            if (in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::Completed], true)) {
                $bookedValueCents += $booking->service->price_cents;
            }

            if (! in_array($booking->status, [BookingStatus::Cancelled, BookingStatus::NoShow], true)) {
                $serviceId = (string) $booking->service_id;
                $services[$serviceId] ??= [
                    'service_id' => $booking->service_id,
                    'name' => $booking->service->name,
                    'bookings_count' => 0,
                ];
                $services[$serviceId]['bookings_count']++;

                $staffId = (string) $booking->staff_id;
                $staffActivity[$staffId] ??= [
                    'staff_id' => $booking->staff_id,
                    'display_name' => $booking->staff->display_name,
                    'bookings_count' => 0,
                ];
                $staffActivity[$staffId]['bookings_count']++;
            }
        }

        usort($services, static fn (array $left, array $right): int => $right['bookings_count'] <=> $left['bookings_count']);
        usort($staffActivity, static fn (array $left, array $right): int => $right['bookings_count'] <=> $left['bookings_count']);

        $upcoming = Booking::query()
            ->active()
            ->with(['service', 'staff.location', 'customer'])
            ->where('starts_at', '>=', CarbonImmutable::now('UTC'))
            ->orderBy('starts_at')
            ->limit(6)
            ->get();

        return response()->json([
            'data' => [
                'period' => [
                    'from' => $periodStart->toDateString(),
                    'to' => $periodEnd->subDay()->toDateString(),
                    'timezone' => $tenant->timezone,
                ],
                'summary' => [
                    'total' => $bookings->count(),
                    'pending' => $statusCounts[BookingStatus::Pending->value],
                    'confirmed' => $statusCounts[BookingStatus::Confirmed->value],
                    'cancelled' => $statusCounts[BookingStatus::Cancelled->value],
                    'completed' => $statusCounts[BookingStatus::Completed->value],
                    'no_show' => $statusCounts[BookingStatus::NoShow->value],
                    'booked_value_cents' => $bookedValueCents,
                ],
                'popular_services' => array_slice(array_values($services), 0, 5),
                'staff_activity' => array_values($staffActivity),
                'upcoming' => $upcoming,
            ],
        ]);
    }
}
