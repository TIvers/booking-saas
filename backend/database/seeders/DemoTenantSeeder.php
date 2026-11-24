<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoTenantSeeder extends Seeder
{
    private const TENANT_SLUG = 'studio-oliva';

    public function run(): void
    {
        DB::transaction(function (): void {
            $tenant = Tenant::query()->firstOrCreate(
                ['slug' => self::TENANT_SLUG],
                [
                    'name' => 'Студия Олива',
                    'timezone' => 'Europe/Moscow',
                    'currency' => 'RUB',
                ],
            );

            if (! $tenant->services()->exists()) {
                $tenant->forceFill([
                    'name' => 'Студия Олива',
                    'timezone' => 'Europe/Moscow',
                    'currency' => 'RUB',
                ])->save();
            }

            DB::select(
                "SELECT set_config('app.tenant_id', ?, true)",
                [(string) $tenant->getKey()],
            );
            app()->instance('currentTenant', $tenant);

            $location = Location::query()->firstOrCreate(
                ['name' => 'Центральный филиал'],
                ['address' => null, 'timezone' => 'Europe/Moscow'],
            );

            $services = [];
            foreach ([
                [
                    'name' => 'Укладка',
                    'description' => 'Уход и укладка',
                    'duration_min' => 30,
                    'buffer_min' => 0,
                    'price_cents' => 250000,
                    'deposit_cents' => 0,
                ],
                [
                    'name' => 'Стрижка',
                    'description' => 'Стрижка и укладка',
                    'duration_min' => 45,
                    'buffer_min' => 0,
                    'price_cents' => 180000,
                    'deposit_cents' => 0,
                ],
                [
                    'name' => 'Окрашивание',
                    'description' => 'Окрашивание волос',
                    'duration_min' => 120,
                    'buffer_min' => 0,
                    'price_cents' => 500000,
                    'deposit_cents' => 100000,
                ],
            ] as $attributes) {
                $service = Service::query()->firstOrCreate(
                    ['name' => $attributes['name']],
                    [...$attributes, 'is_active' => true],
                );
                $services[] = $service->getKey();
            }

            $staff = Staff::query()->firstOrCreate(
                [
                    'location_id' => $location->getKey(),
                    'display_name' => 'Мария Иванова',
                ],
                ['is_active' => true],
            );
            $staff->services()->syncWithoutDetaching($services);

            foreach (range(1, 7) as $weekday) {
                $staff->workSchedules()->firstOrCreate(
                    ['weekday' => $weekday],
                    ['start_time' => '09:00', 'end_time' => '18:00'],
                );
            }
        });
    }
}
