<?php

declare(strict_types=1);

use App\Models\Location;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\TenantFixtures;

uses(DatabaseMigrations::class);

it('restricts tenant-owned records in PostgreSQL and Eloquent scopes', function () {
    $firstTenant = TenantFixtures::createTenant('first-tenant');
    TenantFixtures::createLocation($firstTenant, 'First location');

    $secondTenant = TenantFixtures::createTenant('second-tenant');
    TenantFixtures::createLocation($secondTenant, 'Second location');

    TenantFixtures::activate($firstTenant);

    expect(Location::query()->pluck('name')->all())->toBe(['First location'])
        ->and(DB::table('locations')->pluck('name')->all())->toBe(['First location']);
});

it('returns the selected tenant only after setting the tenant context', function () {
    $tenant = TenantFixtures::createTenant('selected-tenant');

    $response = $this->withHeader('X-Tenant', (string) $tenant->getKey())
        ->getJson('/api/v1/tenant');

    $response->assertOk()
        ->assertJsonPath('data.id', $tenant->getKey())
        ->assertJsonPath('data.slug', 'selected-tenant');
});

it('returns the currency configured for the selected tenant', function () {
    $rubleTenant = TenantFixtures::createTenant('ruble-tenant');
    DB::table('tenants')
        ->where('id', $rubleTenant->getKey())
        ->update(['currency' => 'RUB']);
    $dollarTenant = TenantFixtures::createTenant('dollar-tenant');

    $this->withHeader('X-Tenant', (string) $rubleTenant->getKey())
        ->getJson('/api/v1/tenant')
        ->assertOk()
        ->assertJsonPath('data.currency', 'RUB');

    $this->withHeader('X-Tenant', (string) $dollarTenant->getKey())
        ->getJson('/api/v1/tenant')
        ->assertOk()
        ->assertJsonPath('data.currency', 'USD');
});

it('requires a valid tenant identifier on tenant-scoped API routes', function () {
    $this->getJson('/api/v1/tenant')
        ->assertBadRequest();
});
