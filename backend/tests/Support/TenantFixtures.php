<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Role;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class TenantFixtures
{
    public static function createTenant(string $slug): Tenant
    {
        $id = (int) DB::selectOne(
            "SELECT nextval(pg_get_serial_sequence('tenants', 'id')) AS id"
        )->id;

        self::setDatabaseTenant($id);

        DB::table('tenants')->insert([
            'id' => $id,
            'name' => ucfirst($slug),
            'slug' => $slug,
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Tenant::query()->findOrFail($id);
    }

    public static function activate(Tenant $tenant): void
    {
        self::setDatabaseTenant((int) $tenant->getKey());
        app()->instance('currentTenant', $tenant);
    }

    public static function createMember(Tenant $tenant, array $attributes = []): User
    {
        self::activate($tenant);

        $user = User::factory()->create($attributes);

        Membership::create([
            'user_id' => $user->getKey(),
            'role' => Role::Owner,
        ]);

        return $user;
    }

    public static function createLocation(Tenant $tenant, string $name): Location
    {
        self::activate($tenant);

        return Location::create([
            'name' => $name,
            'timezone' => 'UTC',
        ]);
    }

    private static function setDatabaseTenant(int $tenantId): void
    {
        DB::select(
            "SELECT set_config('app.tenant_id', ?, false)",
            [(string) $tenantId],
        );
    }
}
