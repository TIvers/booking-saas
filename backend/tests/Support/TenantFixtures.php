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

    public static function createMember(
        Tenant $tenant,
        array $attributes = [],
        Role $role = Role::Owner,
    ): User {
        self::activate($tenant);

        $user = User::factory()->make($attributes);
        $userId = (int) DB::selectOne(
            "SELECT nextval(pg_get_serial_sequence('users', 'id')) AS id"
        )->id;
        $now = now();

        // The user stays invisible to SELECT policies until its tenant membership is inserted.
        DB::table('users')->insert([
            'id' => $userId,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at,
            'password' => $user->getAuthPassword(),
            'remember_token' => $user->getRememberToken(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Membership::create([
            'user_id' => $userId,
            'role' => $role,
        ]);

        return User::query()->findOrFail($userId);
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
