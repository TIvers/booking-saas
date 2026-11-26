<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Mail\VerifyAccountEmail;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Mail;

uses(DatabaseMigrations::class);

it('creates a tenant and owner account that can access the new tenant by slug', function () {
    Mail::fake();
    $this->withHeader('Origin', 'http://localhost');

    $registration = $this->postJson('/api/v1/onboarding', [
        'organization_name' => 'Новая студия',
        'slug' => 'new-studio',
        'timezone' => 'Europe/Moscow',
        'currency' => 'RUB',
        'owner_name' => 'Studio Owner',
        'phone' => '+7 900 123-45-67',
        'username' => 'studio-owner',
        'email' => 'owner@example.test',
        'password' => 'demo-owner-password',
        'password_confirmation' => 'demo-owner-password',
        'verification_channel' => 'code',
    ])
        ->assertAccepted()
        ->assertJsonPath('data.tenant.slug', 'new-studio')
        ->assertJsonPath('data.status', 'verification_required');

    $message = Mail::sent(VerifyAccountEmail::class)->first();
    expect($message)->not->toBeNull();

    $this->withHeaders([
        'Origin' => 'http://localhost',
        'X-Tenant-Slug' => 'new-studio',
    ])
        ->postJson('/api/v1/auth/email/verification/code', [
            'challenge_id' => $registration->json('data.verification.challenge_id'),
            'code' => $message->code,
        ])
        ->assertOk()
        ->assertJsonPath('data.user.account_type', 'organization')
        ->assertJsonPath('data.role', Role::Owner->value);

    $this->withHeader('X-Tenant-Slug', 'new-studio')
        ->getJson('/api/v1/tenant')
        ->assertOk()
        ->assertJsonPath('data.name', 'Новая студия')
        ->assertJsonPath('data.currency', 'RUB');

    $this->withHeader('X-Tenant-Slug', 'new-studio')
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.role', Role::Owner->value);
});

it('rejects duplicate tenant slugs during business onboarding', function () {
    Mail::fake();
    $this->withHeader('Origin', 'http://localhost');

    $payload = [
        'organization_name' => 'Первая студия',
        'slug' => 'same-studio',
        'timezone' => 'Europe/Moscow',
        'currency' => 'RUB',
        'owner_name' => 'First Owner',
        'phone' => '+7 900 123-45-67',
        'username' => 'first-owner',
        'email' => 'first-owner@example.test',
        'password' => 'demo-owner-password',
        'password_confirmation' => 'demo-owner-password',
        'verification_channel' => 'link',
    ];

    $this->postJson('/api/v1/onboarding', $payload)
        ->assertAccepted();

    $payload['organization_name'] = 'Вторая студия';
    $payload['email'] = 'second-owner@example.test';

    $this->postJson('/api/v1/onboarding', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('slug');
});

it('returns not found for an unknown tenant slug', function () {
    $this->withHeader('X-Tenant-Slug', 'unknown-studio')
        ->getJson('/api/v1/tenant')
        ->assertNotFound();
});
