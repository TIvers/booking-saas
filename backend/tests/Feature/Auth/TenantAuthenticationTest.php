<?php

declare(strict_types=1);

use App\Enums\Role;
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
        'username' => 'test-customer',
        'email' => 'customer@example.test',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.user.email', 'customer@example.test')
        ->assertJsonPath('data.user.username', 'test-customer')
        ->assertJsonPath('data.role', 'customer');
});

it('allows a customer to register with email and no username', function () {
    $tenant = TenantFixtures::createTenant('email-only-registration-tenant');
    $headers = [
        'Origin' => 'http://localhost',
        'X-Tenant' => (string) $tenant->getKey(),
    ];

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/register', [
            'name' => 'Email Customer',
            'email' => 'email-customer@example.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])
        ->assertCreated()
        ->assertJsonPath('data.user.username', null)
        ->assertJsonPath('data.user.email', 'email-customer@example.test');

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/login', [
            'login' => 'email-customer@example.test',
            'password' => 'correct-horse-battery',
        ])
        ->assertOk()
        ->assertJsonPath('data.user.email', 'email-customer@example.test');
});

it('requires at least one password sign-in identifier at registration', function () {
    $tenant = TenantFixtures::createTenant('identifier-required-tenant');

    $this->withHeaders([
        'Origin' => 'http://localhost',
        'X-Tenant' => (string) $tenant->getKey(),
    ])->postJson('/api/v1/auth/register', [
        'name' => 'Unreachable Customer',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['username', 'email']);
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
            'login' => 'owner@example.test',
            'password' => 'correct-horse-battery',
        ])
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->getKey())
        ->assertJsonPath('data.role', 'owner');
});

it('allows a tenant member to sign in with either username or email', function () {
    $tenant = TenantFixtures::createTenant('legacy-login-tenant');
    $customer = TenantFixtures::createMember($tenant, [
        'email' => 'legacy-customer@example.test',
        'password' => 'correct-horse-battery',
    ], Role::Customer);
    $headers = [
        'Origin' => 'http://localhost',
        'X-Tenant' => (string) $tenant->getKey(),
    ];

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/login', [
            'login' => 'legacy-customer@example.test',
            'password' => 'correct-horse-battery',
        ])
        ->assertOk()
        ->assertJsonPath('data.user.id', $customer->getKey())
        ->assertJsonPath('data.user.username', null);

    $this->withHeaders($headers)
        ->putJson('/api/v1/auth/username', ['username' => 'legacy-client'])
        ->assertOk()
        ->assertJsonPath('data.username', 'legacy-client');

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/login', [
            'login' => 'LEGACY-CUSTOMER@EXAMPLE.TEST',
            'password' => 'correct-horse-battery',
        ])
        ->assertOk()
        ->assertJsonPath('data.user.id', $customer->getKey());

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/login', [
            'login' => 'legacy-client',
            'password' => 'correct-horse-battery',
        ])
        ->assertOk()
        ->assertJsonPath('data.user.id', $customer->getKey());
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

it('allows a member to update their profile name and phone number', function () {
    $tenant = TenantFixtures::createTenant('profile-tenant');
    $user = TenantFixtures::createMember($tenant);
    $headers = ['X-Tenant' => (string) $tenant->getKey()];

    $this->actingAs($user)
        ->withHeaders($headers)
        ->putJson('/api/v1/auth/profile', [
            'name' => 'Иван Иванов',
            'phone' => '+7 (900) 123-45-67',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Иван Иванов')
        ->assertJsonPath('data.phone', '+7 (900) 123-45-67');

    $this->actingAs($user)
        ->withHeaders($headers)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.name', 'Иван Иванов')
        ->assertJsonPath('data.user.phone', '+7 (900) 123-45-67');
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
