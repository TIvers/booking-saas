<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\BookingFixtures;
use Tests\Support\TenantFixtures;
use Tests\TestCase;

uses(DatabaseMigrations::class);

function createPaidPendingBooking(TestCase $test, array $fixtures): int
{
    $headers = ['X-Tenant' => (string) $fixtures['tenant']->getKey()];
    $created = $test->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings', [
            'staff_id' => $fixtures['staff']->getKey(),
            'service_id' => $fixtures['service']->getKey(),
            'starts_at' => $fixtures['starts_at']->toIso8601String(),
        ])
        ->assertCreated();

    $test->actingAs($fixtures['owner'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings/'.$created->json('data.id').'/confirm')
        ->assertOk();

    TenantFixtures::activate($fixtures['tenant']);
    Payment::query()->create([
        'booking_id' => $created->json('data.id'),
        'provider' => 'test',
        'status' => PaymentStatus::Succeeded,
        'amount_cents' => $fixtures['service']->deposit_cents,
        'currency' => 'USD',
    ]);

    return (int) $created->json('data.id');
}

it('retains the paid deposit for a late customer cancellation', function () {
    $fixtures = BookingFixtures::bookable(depositCents: 1000);
    $bookingId = createPaidPendingBooking($this, $fixtures);
    $this->travelTo($fixtures['starts_at']->subHours(12));

    $response = $this->actingAs($fixtures['customer'])
        ->withHeaders(['X-Tenant' => (string) $fixtures['tenant']->getKey()])
        ->postJson('/api/v1/bookings/'.$bookingId.'/cancel');

    $response->assertOk()
        ->assertJsonPath('data.booking.status', 'cancelled')
        ->assertJsonPath('data.cancellation.retained_deposit_cents', 1000)
        ->assertJsonPath('data.cancellation.refund_due_cents', 0);
});

it('refunds the paid deposit amount for a cancellation inside the free window', function () {
    $fixtures = BookingFixtures::bookable(depositCents: 1000);
    $bookingId = createPaidPendingBooking($this, $fixtures);
    $this->travelTo($fixtures['starts_at']->subHours(48));

    $response = $this->actingAs($fixtures['customer'])
        ->withHeaders(['X-Tenant' => (string) $fixtures['tenant']->getKey()])
        ->postJson('/api/v1/bookings/'.$bookingId.'/cancel');

    $response->assertOk()
        ->assertJsonPath('data.cancellation.retained_deposit_cents', 0)
        ->assertJsonPath('data.cancellation.refund_due_cents', 1000);
});

it('does not allow a customer to cancel another customers booking', function () {
    $fixtures = BookingFixtures::bookable();
    $otherCustomer = TenantFixtures::createMember($fixtures['tenant'], role: App\Enums\Role::Customer);
    $headers = ['X-Tenant' => (string) $fixtures['tenant']->getKey()];

    $created = $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings', [
            'staff_id' => $fixtures['staff']->getKey(),
            'service_id' => $fixtures['service']->getKey(),
            'starts_at' => $fixtures['starts_at']->toIso8601String(),
        ])
        ->assertCreated();

    $this->actingAs($otherCustomer)
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings/'.$created->json('data.id').'/cancel')
        ->assertForbidden();
});
