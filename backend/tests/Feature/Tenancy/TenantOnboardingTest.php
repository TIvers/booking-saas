<?php

declare(strict_types=1);

use App\Enums\Role;
use Illuminate\Foundation\Testing\DatabaseMigrations;

uses(DatabaseMigrations::class);

it('creates a tenant and owner account that can access the new tenant by slug', function () {
    $this->withHeader('Origin', 'http://localhost');

    $this->postJson('/api/v1/onboarding', [
        'organization_name' => 'Новая студия',
        'slug' => 'new-studio',
        'timezone' => 'Europe/Moscow',
        'currency' => 'RUB',
        'owner_name' => 'Studio Owner',
        'email' => 'owner@example.test',
        'password' => 'demo-owner-password',
        'password_confirmation' => 'demo-owner-password',
    ])
        ->assertCreated()
        ->assertJsonPath('data.tenant.slug', 'new-studio')
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
    $this->withHeader('Origin', 'http://localhost');

    $payload = [
        'organization_name' => 'Первая студия',
        'slug' => 'same-studio',
        'timezone' => 'Europe/Moscow',
        'currency' => 'RUB',
        'owner_name' => 'First Owner',
        'email' => 'first-owner@example.test',
        'password' => 'demo-owner-password',
        'password_confirmation' => 'demo-owner-password',
    ];

    $this->postJson('/api/v1/onboarding', $payload)
        ->assertCreated();

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
