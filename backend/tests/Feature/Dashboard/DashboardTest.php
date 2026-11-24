<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\BookingFixtures;
use Tests\Support\TenantFixtures;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-01-15T12:00:00Z'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('returns monthly booking metrics to a tenant manager only', function () {
    $fixtures = BookingFixtures::bookable();
    Booking::query()->create([
        'staff_id' => $fixtures['staff']->getKey(),
        'service_id' => $fixtures['service']->getKey(),
        'customer_id' => $fixtures['customer']->getKey(),
        'starts_at' => $fixtures['starts_at'],
        'ends_at' => $fixtures['starts_at']->addMinutes(30),
        'status' => BookingStatus::Confirmed,
    ]);
    $headers = ['X-Tenant' => (string) $fixtures['tenant']->getKey()];

    $this->actingAs($fixtures['owner'])
        ->withHeaders($headers)
        ->getJson('/api/v1/manage/dashboard')
        ->assertOk()
        ->assertJsonPath('data.period.from', '2026-01-01')
        ->assertJsonPath('data.summary.total', 1)
        ->assertJsonPath('data.summary.confirmed', 1)
        ->assertJsonPath('data.summary.booked_value_cents', 5000)
        ->assertJsonPath('data.popular_services.0.name', 'Consultation')
        ->assertJsonPath('data.staff_activity.0.display_name', 'Alex');

    $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->getJson('/api/v1/manage/dashboard')
        ->assertForbidden();
});

it('filters booking dates using the tenant timezone', function () {
    $fixtures = BookingFixtures::bookable();
    TenantFixtures::activate($fixtures['tenant']);
    $fixtures['tenant']->update(['timezone' => 'Europe/Moscow']);

    $startsAt = CarbonImmutable::parse('2026-01-15 10:30', 'Europe/Moscow')->utc();
    $booking = Booking::query()->create([
        'staff_id' => $fixtures['staff']->getKey(),
        'service_id' => $fixtures['service']->getKey(),
        'customer_id' => $fixtures['customer']->getKey(),
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addMinutes(30),
        'status' => BookingStatus::Confirmed,
    ]);

    $this->actingAs($fixtures['owner'])
        ->withHeader('X-Tenant', (string) $fixtures['tenant']->getKey())
        ->getJson('/api/v1/bookings?from=2026-01-15&to=2026-01-15')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $booking->getKey());
});
