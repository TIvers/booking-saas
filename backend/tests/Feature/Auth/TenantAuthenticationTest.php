<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\TenantFixtures;

uses(DatabaseMigrations::class);

it('registers a customer inside the selected tenant', function () {
    $tenant = TenantFixtures::createTenant('registration-tenant');

    $response = $this->withHeaders([
        'Origin' => 'http://localhost',
        'X-Tenant' => (string) $tenant->getKey(),
    ])->postJson('/api/v1/auth/register', [
        'name' => 'Test Customer',
        'email' => 'customer@example.test',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.user.email', 'customer@example.test')
        ->assertJsonPath('data.role', 'customer');
});

it('authenticates a tenant member and returns the selected tenant context', function () {
    $tenant = TenantFixtures::createTenant('login-tenant');
    $user = TenantFixtures::createMember($tenant, [
        'email' => 'owner@example.test',
        'password' => 'correct-horse-battery',
    ]);

    $headers = [
        'Origin' => 'http://localhost',
        'X-Tenant' => (string) $tenant->getKey(),
    ];

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/login', [
            'email' => 'owner@example.test',
            'password' => 'correct-horse-battery',
        ])
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->getKey())
        ->assertJsonPath('data.role', 'owner');
});

it('returns the authenticated member in the selected tenant', function () {
    $tenant = TenantFixtures::createTenant('me-tenant');
    $user = TenantFixtures::createMember($tenant);

    $this->actingAs($user)
        ->withHeader('X-Tenant', (string) $tenant->getKey())
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.tenant.id', $tenant->getKey());
});

it('does not allow a member to use another tenant context', function () {
    $memberTenant = TenantFixtures::createTenant('member-tenant');
    $user = TenantFixtures::createMember($memberTenant);
    $otherTenant = TenantFixtures::createTenant('other-tenant');

    $this->actingAs($user)
        ->withHeader('X-Tenant', (string) $otherTenant->getKey())
        ->getJson('/api/v1/auth/me')
        ->assertForbidden();
});
