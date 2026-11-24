<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Membership;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\TenantFixtures;

uses(DatabaseMigrations::class);

it('lists only active services from the selected tenant', function () {
    $tenant = TenantFixtures::createTenant('catalog-tenant');
    TenantFixtures::activate($tenant);
    Service::query()->create([
        'name' => 'Visible service',
        'duration_min' => 30,
        'price_cents' => 2500,
    ]);

    $otherTenant = TenantFixtures::createTenant('other-catalog-tenant');
    TenantFixtures::activate($otherTenant);
    $foreignService = Service::query()->create([
        'name' => 'Other tenant service',
        'duration_min' => 30,
        'price_cents' => 2500,
    ]);

    $this->withHeader('X-Tenant', (string) $tenant->getKey())
        ->getJson('/api/v1/services')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Visible service');

    $this->withHeader('X-Tenant', (string) $tenant->getKey())
        ->getJson("/api/v1/services/{$foreignService->getKey()}")
        ->assertNotFound();
});

it('allows owners to create a service and rejects a deposit above its price', function () {
    $tenant = TenantFixtures::createTenant('service-owner-tenant');
    $owner = TenantFixtures::createMember($tenant);
    $headers = ['X-Tenant' => (string) $tenant->getKey()];

    $createdService = $this->actingAs($owner)
        ->withHeaders($headers)
        ->postJson('/api/v1/services', [
            'name' => 'Consultation',
            'duration_min' => 45,
            'price_cents' => 5000,
            'deposit_cents' => 1000,
        ])
        ->assertCreated()
        ->assertJsonPath('data.tenant_id', $tenant->getKey());

    $serviceId = $createdService->json('data.id');

    $this->actingAs($owner)
        ->withHeaders($headers)
        ->deleteJson("/api/v1/services/{$serviceId}")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $this->actingAs($owner)
        ->withHeaders($headers)
        ->getJson('/api/v1/manage/services')
        ->assertOk()
        ->assertJsonPath('data.0.is_active', false);

    $this->actingAs($owner)
        ->withHeaders($headers)
        ->postJson('/api/v1/services', [
            'name' => 'Invalid consultation',
            'duration_min' => 45,
            'price_cents' => 5000,
            'deposit_cents' => 5001,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('deposit_cents');
});

it('does not allow a customer to manage the catalog', function () {
    $tenant = TenantFixtures::createTenant('customer-tenant');
    $customer = User::factory()->create();
    TenantFixtures::activate($tenant);
    Membership::query()->create([
        'user_id' => $customer->getKey(),
        'role' => Role::Customer,
    ]);

    $this->actingAs($customer)
        ->withHeader('X-Tenant', (string) $tenant->getKey())
        ->postJson('/api/v1/services', [
            'name' => 'Restricted service',
            'duration_min' => 30,
            'price_cents' => 2500,
        ])
        ->assertForbidden();
});

it('creates staff only with locations and services from the current tenant', function () {
    $tenant = TenantFixtures::createTenant('staff-tenant');
    $owner = TenantFixtures::createMember($tenant);
    $location = TenantFixtures::createLocation($tenant, 'Main');
    $service = Service::query()->create([
        'name' => 'Haircut',
        'duration_min' => 30,
        'price_cents' => 3000,
    ]);

    $otherTenant = TenantFixtures::createTenant('foreign-staff-tenant');
    TenantFixtures::activate($otherTenant);
    $foreignService = Service::query()->create([
        'name' => 'Foreign service',
        'duration_min' => 30,
        'price_cents' => 3000,
    ]);

    $headers = ['X-Tenant' => (string) $tenant->getKey()];
    $payload = [
        'location_id' => $location->getKey(),
        'display_name' => 'Alex',
        'service_ids' => [$service->getKey()],
    ];

    $this->actingAs($owner)
        ->withHeaders($headers)
        ->postJson('/api/v1/staff', $payload)
        ->assertCreated()
        ->assertJsonPath('data.services.0.id', $service->getKey());

    $payload['service_ids'] = [$foreignService->getKey()];

    $this->actingAs($owner)
        ->withHeaders($headers)
        ->postJson('/api/v1/staff', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('service_ids.0');
});

it('replaces weekly schedules atomically and rejects overlapping work ranges', function () {
    $tenant = TenantFixtures::createTenant('schedule-tenant');
    $owner = TenantFixtures::createMember($tenant);
    $location = TenantFixtures::createLocation($tenant, 'Main');
    TenantFixtures::activate($tenant);
    $staff = Staff::query()->create([
        'location_id' => $location->getKey(),
        'display_name' => 'Sam',
    ]);

    $headers = ['X-Tenant' => (string) $tenant->getKey()];
    $validSchedule = [
        'work_schedules' => [
            ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '12:00'],
            ['weekday' => 1, 'start_time' => '12:00', 'end_time' => '17:00'],
        ],
        'breaks' => [
            ['weekday' => 1, 'start_time' => '13:00', 'end_time' => '13:30'],
        ],
    ];

    $this->actingAs($owner)
        ->withHeaders($headers)
        ->putJson("/api/v1/staff/{$staff->getKey()}/schedule", $validSchedule)
        ->assertOk()
        ->assertJsonCount(2, 'data.work_schedules')
        ->assertJsonCount(1, 'data.breaks');

    $invalidSchedule = $validSchedule;
    $invalidSchedule['work_schedules'][1]['start_time'] = '11:30';

    $this->actingAs($owner)
        ->withHeaders($headers)
        ->putJson("/api/v1/staff/{$staff->getKey()}/schedule", $invalidSchedule)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('work_schedules');

    $invalidBreak = $validSchedule;
    $invalidBreak['breaks'] = [
        ['weekday' => 1, 'start_time' => '08:00', 'end_time' => '08:30'],
    ];

    $this->actingAs($owner)
        ->withHeaders($headers)
        ->putJson("/api/v1/staff/{$staff->getKey()}/schedule", $invalidBreak)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('breaks');

    $this->actingAs($owner)
        ->withHeaders($headers)
        ->getJson("/api/v1/staff/{$staff->getKey()}/schedule")
        ->assertOk()
        ->assertJsonCount(2, 'data.work_schedules');
});

it('sets a date exception as a day off without retaining working hours', function () {
    $tenant = TenantFixtures::createTenant('exception-tenant');
    $owner = TenantFixtures::createMember($tenant);
    $location = TenantFixtures::createLocation($tenant, 'Main');
    TenantFixtures::activate($tenant);
    $staff = Staff::query()->create([
        'location_id' => $location->getKey(),
        'display_name' => 'Sam',
    ]);

    $this->actingAs($owner)
        ->withHeader('X-Tenant', (string) $tenant->getKey())
        ->putJson("/api/v1/staff/{$staff->getKey()}/schedule/exceptions/2026-12-25", [
            'is_day_off' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.is_day_off', true)
        ->assertJsonPath('data.start_time', null)
        ->assertJsonPath('data.end_time', null);
});
