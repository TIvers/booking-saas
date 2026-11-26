<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Service;
use App\Models\Staff;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

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
            DB::table('tenant_slug_directory')->updateOrInsert(
                ['tenant_id' => $tenant->getKey()],
                [
                    'slug' => $tenant->slug,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
            $this->provisionOwner();

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

    private function provisionOwner(): void
    {
        $email = config('booking.demo_owner.email');
        $password = config('booking.demo_owner.password');

        if (
            ! is_string($email)
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || ! is_string($password)
            || strlen($password) < 12
        ) {
            throw new RuntimeException(
                'Set DEMO_OWNER_EMAIL and a DEMO_OWNER_PASSWORD of at least 12 characters in GitHub Secrets.'
            );
        }

        $owner = DB::table('users')->where('email', $email)->first(['id']);
        $now = now();

        if ($owner === null) {
            $ownerId = (int) DB::selectOne(
                "SELECT nextval(pg_get_serial_sequence('users', 'id')) AS id"
            )->id;

            DB::table('users')->insert([
                'id' => $ownerId,
                'name' => 'Владелец',
                'email' => $email,
                'password' => Hash::make($password),
                'email_verified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $ownerId = (int) $owner->id;

            DB::table('users')->where('id', $ownerId)->update([
                'password' => Hash::make($password),
                'updated_at' => $now,
            ]);
        }

        $membership = Membership::query()->firstOrNew(['user_id' => $ownerId]);
        $membership->role = Role::Owner;
        $membership->save();
    }
}
