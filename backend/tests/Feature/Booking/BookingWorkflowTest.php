<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Jobs\ReleaseExpiredReservations;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BookingFixtures;
use Tests\Support\TenantFixtures;

uses(DatabaseMigrations::class);

it('returns generated slots and creates a confirmed booking without a deposit', function () {
    $fixtures = BookingFixtures::bookable(bufferMinutes: 15);
    $headers = ['X-Tenant' => (string) $fixtures['tenant']->getKey()];
    $date = $fixtures['starts_at']->format('Y-m-d');

    $this->getJson(
        '/api/v1/slots?'.http_build_query([
            'staff_id' => $fixtures['staff']->getKey(),
            'service_id' => $fixtures['service']->getKey(),
            'date' => $date,
        ]),
        $headers,
    )
        ->assertOk()
        ->assertJsonPath('data.0.starts_at', $fixtures['starts_at']->toIso8601String());

    $response = $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings', [
            'staff_id' => $fixtures['staff']->getKey(),
            'service_id' => $fixtures['service']->getKey(),
            'starts_at' => $fixtures['starts_at']->toIso8601String(),
        ]);

    $response->assertCreated()->assertJsonPath('data.status', BookingStatus::Confirmed->value);

    expect(CarbonImmutable::parse($response->json('data.ends_at'))->equalTo(
        $fixtures['starts_at']->addMinutes(45),
    ))->toBeTrue();
});

it('creates a temporary pending reservation for a service with a deposit', function () {
    Queue::fake();
    $fixtures = BookingFixtures::bookable(depositCents: 1000);
    $headers = ['X-Tenant' => (string) $fixtures['tenant']->getKey()];

    $response = $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings', [
            'staff_id' => $fixtures['staff']->getKey(),
            'service_id' => $fixtures['service']->getKey(),
            'starts_at' => $fixtures['starts_at']->toIso8601String(),
        ]);

    $response->assertCreated()->assertJsonPath('data.status', BookingStatus::Pending->value);
    expect($response->json('data.expires_at'))->not->toBeNull();

    Queue::assertPushed(ReleaseExpiredReservations::class, function (ReleaseExpiredReservations $job) use ($response, $fixtures): bool {
        return $job->bookingId === $response->json('data.id')
            && $job->tenantId === $fixtures['tenant']->getKey();
    });
});

it('allows a tenant manager to confirm an unexpired reservation', function () {
    $fixtures = BookingFixtures::bookable(depositCents: 1000);
    $headers = ['X-Tenant' => (string) $fixtures['tenant']->getKey()];

    $created = $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings', [
            'staff_id' => $fixtures['staff']->getKey(),
            'service_id' => $fixtures['service']->getKey(),
            'starts_at' => $fixtures['starts_at']->toIso8601String(),
        ])
        ->assertCreated();

    $confirmed = $this->actingAs($fixtures['owner'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings/'.$created->json('data.id').'/confirm');

    $confirmed->assertOk()
        ->assertJsonPath('data.status', BookingStatus::Confirmed->value)
        ->assertJsonPath('data.expires_at', null);
});

it('allows managers to complete a started booking but not a future booking or a customer', function () {
    $fixtures = BookingFixtures::bookable();
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

    $this->actingAs($fixtures['owner'])
        ->withHeaders($headers)
        ->postJson("/api/v1/bookings/{$bookingId}/complete")
        ->assertStatus(409);

    $this->travelTo($fixtures['starts_at']->addMinute());

    $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson("/api/v1/bookings/{$bookingId}/complete")
        ->assertForbidden();

    $this->actingAs($fixtures['owner'])
        ->withHeaders($headers)
        ->postJson("/api/v1/bookings/{$bookingId}/complete")
        ->assertOk()
        ->assertJsonPath('data.status', BookingStatus::Completed->value);
});

it('leaves the original booking unchanged when a reschedule conflicts', function () {
    $fixtures = BookingFixtures::bookable(durationMinutes: 30);
    $headers = ['X-Tenant' => (string) $fixtures['tenant']->getKey()];
    $create = fn (CarbonImmutable $startsAt) => $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings', [
            'staff_id' => $fixtures['staff']->getKey(),
            'service_id' => $fixtures['service']->getKey(),
            'starts_at' => $startsAt->toIso8601String(),
        ]);

    $first = $create($fixtures['starts_at'])->assertCreated();
    $second = $create($fixtures['starts_at']->addHour())->assertCreated();

    $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings/'.$first->json('data.id').'/reschedule', [
            'starts_at' => $second->json('data.starts_at'),
        ])
        ->assertStatus(409)
        ->assertJsonPath('error', 'slot_already_taken');

    TenantFixtures::activate($fixtures['tenant']);
    $booking = Booking::query()->findOrFail($first->json('data.id'));

    expect($booking->starts_at->equalTo($fixtures['starts_at']))->toBeTrue();
});

it('reschedules a booking into an interval that overlaps its old interval', function () {
    $fixtures = BookingFixtures::bookable(durationMinutes: 30);
    $headers = ['X-Tenant' => (string) $fixtures['tenant']->getKey()];

    $created = $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings', [
            'staff_id' => $fixtures['staff']->getKey(),
            'service_id' => $fixtures['service']->getKey(),
            'starts_at' => $fixtures['starts_at']->toIso8601String(),
        ])
        ->assertCreated();

    $newStart = $fixtures['starts_at']->addMinutes(15);
    $rescheduled = $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings/'.$created->json('data.id').'/reschedule', [
            'starts_at' => $newStart->toIso8601String(),
        ])
        ->assertOk();

    expect(CarbonImmutable::parse($rescheduled->json('data.starts_at'))->equalTo($newStart))->toBeTrue();
});

it('releases an expired pending reservation under its tenant RLS context', function () {
    $fixtures = BookingFixtures::bookable(depositCents: 1000);
    $headers = ['X-Tenant' => (string) $fixtures['tenant']->getKey()];

    $created = $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings', [
            'staff_id' => $fixtures['staff']->getKey(),
            'service_id' => $fixtures['service']->getKey(),
            'starts_at' => $fixtures['starts_at']->toIso8601String(),
        ])
        ->assertCreated();

    DB::table('bookings')
        ->where('id', $created->json('data.id'))
        ->update(['expires_at' => now()->subSecond()]);

    (new ReleaseExpiredReservations(
        (int) $created->json('data.id'),
        (int) $fixtures['tenant']->getKey(),
    ))->handle();

    TenantFixtures::activate($fixtures['tenant']);
    expect(Booking::query()->findOrFail($created->json('data.id'))->status)
        ->toBe(BookingStatus::Cancelled);

    $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings', [
            'staff_id' => $fixtures['staff']->getKey(),
            'service_id' => $fixtures['service']->getKey(),
            'starts_at' => $fixtures['starts_at']->toIso8601String(),
        ])
        ->assertCreated();
});
