<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\BookingFixtures;

uses(DatabaseMigrations::class);

it('allows only the tenant owner to update booking policies', function () {
    $fixtures = BookingFixtures::bookable();
    $headers = ['X-Tenant' => (string) $fixtures['tenant']->getKey()];
    $settings = [
        'allow_customer_rescheduling' => false,
        'max_customer_reschedules' => 2,
        'booking_lead_time_minutes' => 1440,
    ];

    $this->actingAs($fixtures['owner'])
        ->withHeaders($headers)
        ->putJson('/api/v1/manage/booking-settings', $settings)
        ->assertOk()
        ->assertJsonPath('data.max_customer_reschedules', 2)
        ->assertJsonPath('data.booking_lead_time_minutes', 1440);

    $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->putJson('/api/v1/manage/booking-settings', $settings)
        ->assertForbidden();
});

it('applies a service lead-time override instead of the tenant default', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'));
    $fixtures = BookingFixtures::bookable();
    $fixtures['tenant']->update(['booking_lead_time_minutes' => 82 * 60]);
    $fixtures['service']->update(['booking_lead_time_minutes' => 80 * 60]);
    $headers = ['X-Tenant' => (string) $fixtures['tenant']->getKey()];
    $query = http_build_query([
        'staff_id' => $fixtures['staff']->getKey(),
        'service_id' => $fixtures['service']->getKey(),
        'date' => $fixtures['starts_at']->format('Y-m-d'),
    ]);

    $this->getJson('/api/v1/slots?'.$query, $headers)
        ->assertOk()
        ->assertJsonPath('data.0.starts_at', $fixtures['starts_at']->toIso8601String());

    $fixtures['service']->update(['booking_lead_time_minutes' => null]);

    $this->getJson('/api/v1/slots?'.$query, $headers)
        ->assertOk()
        ->assertJsonPath(
            'data.0.starts_at',
            $fixtures['starts_at']->addHour()->toIso8601String(),
        );

    $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings', [
            'staff_id' => $fixtures['staff']->getKey(),
            'service_id' => $fixtures['service']->getKey(),
            'starts_at' => $fixtures['starts_at']->toIso8601String(),
        ])
        ->assertStatus(409);

    $fixtures['service']->update(['booking_lead_time_minutes' => 80 * 60]);

    $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings', [
            'staff_id' => $fixtures['staff']->getKey(),
            'service_id' => $fixtures['service']->getKey(),
            'starts_at' => $fixtures['starts_at']->toIso8601String(),
        ])
        ->assertCreated();
});

it('enforces the customer rescheduling limit', function () {
    $fixtures = BookingFixtures::bookable();
    $fixtures['tenant']->update([
        'allow_customer_rescheduling' => false,
        'max_customer_reschedules' => 1,
    ]);
    $headers = ['X-Tenant' => (string) $fixtures['tenant']->getKey()];

    $created = $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings', [
            'staff_id' => $fixtures['staff']->getKey(),
            'service_id' => $fixtures['service']->getKey(),
            'starts_at' => $fixtures['starts_at']->toIso8601String(),
        ])
        ->assertCreated();

    $bookingId = $created->json('data.id');

    $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson("/api/v1/bookings/{$bookingId}/reschedule", [
            'starts_at' => $fixtures['starts_at']->addMinutes(15)->toIso8601String(),
        ])
        ->assertForbidden();

    $fixtures['tenant']->update(['allow_customer_rescheduling' => true]);

    $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson("/api/v1/bookings/{$bookingId}/reschedule", [
            'starts_at' => $fixtures['starts_at']->addMinutes(15)->toIso8601String(),
        ])
        ->assertOk();

    $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson("/api/v1/bookings/{$bookingId}/reschedule", [
            'starts_at' => $fixtures['starts_at']->addMinutes(30)->toIso8601String(),
        ])
        ->assertStatus(409);
});
