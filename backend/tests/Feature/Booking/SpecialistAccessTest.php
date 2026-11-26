<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Booking;
use App\Models\Membership;
use App\Models\Staff;
use App\Models\WorkSchedule;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\BookingFixtures;
use Tests\Support\TenantFixtures;

uses(DatabaseMigrations::class);

it('allows only the organization owner to create a specialist account', function () {
    $fixtures = BookingFixtures::bookable();
    $tenant = $fixtures['tenant'];
    $manager = TenantFixtures::createMember(
        $tenant,
        ['email' => 'manager@example.test'],
        Role::Manager,
    );

    $this->actingAs($manager)
        ->withHeaders(['X-Tenant' => (string) $tenant->getKey()])
        ->putJson("/api/v1/staff/{$fixtures['staff']->getKey()}/account", [
            'username' => 'alex.staff',
            'password' => 'specialist-password-123',
            'password_confirmation' => 'specialist-password-123',
        ])
        ->assertForbidden();
});

it('limits specialists to their own bookings and gives them read-only schedule access', function () {
    $fixtures = BookingFixtures::bookable();
    $tenant = $fixtures['tenant'];
    $headers = [
        'X-Tenant' => (string) $tenant->getKey(),
        'Origin' => 'http://localhost',
    ];
    $specialistCredentials = [
        'username' => 'alex.staff',
        'password' => 'specialist-password-123',
        'password_confirmation' => 'specialist-password-123',
    ];

    $this->actingAs($fixtures['owner'])
        ->withHeaders($headers)
        ->putJson("/api/v1/staff/{$fixtures['staff']->getKey()}/account", $specialistCredentials)
        ->assertCreated()
        ->assertJsonPath('data.account_username', 'alex.staff');

    TenantFixtures::activate($tenant);
    $otherStaff = Staff::query()->create([
        'location_id' => $fixtures['staff']->location_id,
        'display_name' => 'Other specialist',
    ]);
    $otherStaff->services()->attach($fixtures['service']->getKey());
    WorkSchedule::query()->create([
        'staff_id' => $otherStaff->getKey(),
        'weekday' => $fixtures['starts_at']->dayOfWeekIso,
        'start_time' => '09:00',
        'end_time' => '17:00',
    ]);

    $ownBooking = $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings', [
            'staff_id' => $fixtures['staff']->getKey(),
            'service_id' => $fixtures['service']->getKey(),
            'starts_at' => $fixtures['starts_at']->toIso8601String(),
        ])
        ->assertCreated()
        ->json('data.id');

    $otherBooking = $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings', [
            'staff_id' => $otherStaff->getKey(),
            'service_id' => $fixtures['service']->getKey(),
            'starts_at' => $fixtures['starts_at']->addHour()->toIso8601String(),
        ])
        ->assertCreated()
        ->json('data.id');

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/organization/login', [
            'login' => $specialistCredentials['username'],
            'password' => $specialistCredentials['password'],
        ])
        ->assertOk()
        ->assertJsonPath('data.role', 'staff');

    TenantFixtures::activate($tenant);
    $specialist = Membership::query()
        ->where('username', $specialistCredentials['username'])
        ->firstOrFail()
        ->user;
    $this->actingAs($specialist);

    $this->withHeaders($headers)
        ->getJson('/api/v1/bookings')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownBooking);

    $this->withHeaders($headers)
        ->postJson("/api/v1/bookings/{$otherBooking}/cancel", ['reason' => 'Not my booking'])
        ->assertForbidden();

    $this->withHeaders($headers)
        ->getJson('/api/v1/staff/me/schedule')
        ->assertOk()
        ->assertJsonPath('data.staff.display_name', 'Alex')
        ->assertJsonCount(1, 'data.work_schedules');

    $this->withHeaders($headers)
        ->putJson("/api/v1/staff/{$fixtures['staff']->getKey()}/schedule", [
            'work_schedules' => [],
            'breaks' => [],
        ])
        ->assertForbidden();

    $this->withHeaders($headers)
        ->postJson("/api/v1/bookings/{$ownBooking}/cancel", ['reason' => 'Перенос невозможен'])
        ->assertOk()
        ->assertJsonMissingPath('data.booking.changes');

    TenantFixtures::activate($tenant);
    expect(
        Booking::query()->findOrFail($ownBooking)->changes()->firstOrFail()->reason,
    )->toBe('Перенос невозможен');

    $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->getJson("/api/v1/bookings/{$ownBooking}")
        ->assertOk()
        ->assertJsonPath('data.changes.0.reason', 'Перенос невозможен');
});
