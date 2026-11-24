<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Jobs\ReleaseExpiredReservations;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BookingFixtures;
use Tests\Support\TenantFixtures;

uses(DatabaseMigrations::class);

it('confirms a deposit booking after a successful demo payment', function () {
    config()->set('payments.demo_enabled', true);
    Queue::fake();
    $fixtures = BookingFixtures::bookable(depositCents: 1000);
    $headers = ['X-Tenant' => (string) $fixtures['tenant']->getKey()];
    $booking = $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings', [
            'staff_id' => $fixtures['staff']->getKey(),
            'service_id' => $fixtures['service']->getKey(),
            'starts_at' => $fixtures['starts_at']->toIso8601String(),
        ])
        ->assertCreated()
        ->json('data');

    $payment = $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings/'.$booking['id'].'/demo-payment')
        ->assertOk()
        ->assertJsonPath('data.status', PaymentStatus::Pending->value)
        ->assertJsonPath('data.amount_cents', 1000)
        ->assertJsonPath('data.currency', 'USD')
        ->json('data');

    $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings/'.$booking['id'].'/demo-payment/result', [
            'outcome' => 'succeeded',
        ])
        ->assertOk()
        ->assertJsonPath('data.payment.id', $payment['id'])
        ->assertJsonPath('data.payment.status', PaymentStatus::Succeeded->value)
        ->assertJsonPath('data.booking.status', BookingStatus::Confirmed->value)
        ->assertJsonPath('data.booking.expires_at', null);

    $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings/'.$booking['id'].'/cancel')
        ->assertOk()
        ->assertJsonPath('data.cancellation.refund_due_cents', 0);
});

it('cancels a deposit booking on a failed demo payment and makes the result idempotent', function () {
    config()->set('payments.demo_enabled', true);
    Queue::fake();
    $fixtures = BookingFixtures::bookable(depositCents: 1000);
    $headers = ['X-Tenant' => (string) $fixtures['tenant']->getKey()];
    $booking = $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings', [
            'staff_id' => $fixtures['staff']->getKey(),
            'service_id' => $fixtures['service']->getKey(),
            'starts_at' => $fixtures['starts_at']->toIso8601String(),
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings/'.$booking['id'].'/demo-payment')
        ->assertOk();

    $url = '/api/v1/bookings/'.$booking['id'].'/demo-payment/result';
    $payload = ['outcome' => 'failed'];

    $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson($url, $payload)
        ->assertOk()
        ->assertJsonPath('data.payment.status', PaymentStatus::Failed->value)
        ->assertJsonPath('data.booking.status', BookingStatus::Cancelled->value);

    $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson($url, $payload)
        ->assertOk()
        ->assertJsonPath('data.payment.status', PaymentStatus::Failed->value);

    $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson($url, ['outcome' => 'succeeded'])
        ->assertStatus(409);
});

it('does not expose demo payment routes when demo mode is disabled', function () {
    config()->set('payments.demo_enabled', false);
    Queue::fake();
    $fixtures = BookingFixtures::bookable(depositCents: 1000);
    $headers = ['X-Tenant' => (string) $fixtures['tenant']->getKey()];
    $booking = $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings', [
            'staff_id' => $fixtures['staff']->getKey(),
            'service_id' => $fixtures['service']->getKey(),
            'starts_at' => $fixtures['starts_at']->toIso8601String(),
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings/'.$booking['id'].'/demo-payment')
        ->assertNotFound();

    TenantFixtures::activate($fixtures['tenant']);
    expect(Payment::query()->where('booking_id', $booking['id'])->exists())->toBeFalse();
});

it('prevents a customer from simulating another customer’s payment', function () {
    config()->set('payments.demo_enabled', true);
    Queue::fake();
    $fixtures = BookingFixtures::bookable(depositCents: 1000);
    $otherCustomer = TenantFixtures::createMember(
        $fixtures['tenant'],
        role: Role::Customer,
    );
    $headers = ['X-Tenant' => (string) $fixtures['tenant']->getKey()];
    $booking = $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings', [
            'staff_id' => $fixtures['staff']->getKey(),
            'service_id' => $fixtures['service']->getKey(),
            'starts_at' => $fixtures['starts_at']->toIso8601String(),
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($otherCustomer)
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings/'.$booking['id'].'/demo-payment')
        ->assertForbidden();
});

it('marks an abandoned demo payment failed when its reservation expires', function () {
    config()->set('payments.demo_enabled', true);
    Queue::fake();
    $fixtures = BookingFixtures::bookable(depositCents: 1000);
    $headers = ['X-Tenant' => (string) $fixtures['tenant']->getKey()];
    $booking = $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings', [
            'staff_id' => $fixtures['staff']->getKey(),
            'service_id' => $fixtures['service']->getKey(),
            'starts_at' => $fixtures['starts_at']->toIso8601String(),
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($fixtures['customer'])
        ->withHeaders($headers)
        ->postJson('/api/v1/bookings/'.$booking['id'].'/demo-payment')
        ->assertOk();

    TenantFixtures::activate($fixtures['tenant']);
    DB::table('bookings')
        ->where('id', $booking['id'])
        ->update(['expires_at' => now()->subSecond()]);

    (new ReleaseExpiredReservations(
        (int) $booking['id'],
        (int) $fixtures['tenant']->getKey(),
    ))->handle();

    TenantFixtures::activate($fixtures['tenant']);
    expect(Booking::query()->findOrFail($booking['id'])->status)
        ->toBe(BookingStatus::Cancelled);
    expect(Payment::query()->where('booking_id', $booking['id'])->value('status'))
        ->toBe(PaymentStatus::Failed->value);
});
