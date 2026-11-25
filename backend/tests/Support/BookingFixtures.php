<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Role;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class BookingFixtures
{
    /**
     * @return array{
     *     tenant: Tenant,
     *     owner: User,
     *     customer: User,
     *     service: Service,
     *     staff: Staff,
     *     starts_at: CarbonImmutable
     * }
     */
    public static function bookable(
        int $depositCents = 0,
        int $bufferMinutes = 0,
        int $durationMinutes = 30,
    ): array {
        $tenant = TenantFixtures::createTenant('booking-'.Str::lower(Str::random(8)));
        $owner = TenantFixtures::createMember($tenant, role: Role::Owner);
        $customer = TenantFixtures::createMember($tenant, role: Role::Customer);
        $location = TenantFixtures::createLocation($tenant, 'Main');

        TenantFixtures::activate($tenant);

        $service = Service::query()->create([
            'name' => 'Consultation',
            'duration_min' => $durationMinutes,
            'buffer_min' => $bufferMinutes,
            'price_cents' => 5000,
            'deposit_cents' => $depositCents,
            'is_active' => true,
        ]);

        $staff = Staff::query()->create([
            'location_id' => $location->getKey(),
            'display_name' => 'Alex',
            'is_active' => true,
        ]);

        $staff->services()->attach($service->getKey());

        $date = CarbonImmutable::now('UTC')->addDays(3)->startOfDay();
        while ($date->dayOfWeekIso > 5) {
            $date = $date->addDay();
        }

        WorkSchedule::query()->create([
            'staff_id' => $staff->getKey(),
            'weekday' => $date->dayOfWeekIso,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        return [
            'tenant' => $tenant,
            'owner' => $owner,
            'customer' => $customer,
            'service' => $service,
            'staff' => $staff,
            'starts_at' => $date->addHours(10),
        ];
    }
}
