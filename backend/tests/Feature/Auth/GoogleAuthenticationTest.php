<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Membership;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Auth\GoogleIdentityVerifier;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\TenantFixtures;

uses(DatabaseMigrations::class);

it('creates a Google customer account and reuses it on later sign-ins', function () {
    $tenant = TenantFixtures::createTenant('google-auth-tenant');
    config()->set('services.google.client_id', 'google-client-id');

    $verifier = Mockery::mock(GoogleIdentityVerifier::class);
    $verifier->shouldReceive('verify')
        ->twice()
        ->with('signed-google-token')
        ->andReturn([
            'sub' => 'google-subject-1',
            'email' => 'customer@example.test',
            'name' => 'Google Customer',
        ]);
    app()->instance(GoogleIdentityVerifier::class, $verifier);

    $headers = ['X-Tenant' => (string) $tenant->getKey()];
    $payload = ['credential' => 'signed-google-token'];

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/google', $payload)
        ->assertOk()
        ->assertJsonPath('data.user.name', 'Google Customer')
        ->assertJsonPath('data.user.email', 'customer@example.test')
        ->assertJsonPath('data.user.google_linked', true)
        ->assertJsonPath('data.role', 'customer');

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/google', $payload)
        ->assertOk()
        ->assertJsonPath('data.user.username', null);

    expect(User::query()->where('email', 'customer@example.test')->count())->toBe(1)
        ->and(UserIdentity::query()->where('provider', 'google')->count())->toBe(1)
        ->and(Membership::query()->where('role', 'customer')->count())->toBe(1);
});

it('links a Google account to an existing organization member by verified email', function () {
    $tenant = TenantFixtures::createTenant('google-existing-user-tenant');
    $owner = TenantFixtures::createMember(
        $tenant,
        ['name' => 'Existing Owner', 'email' => 'owner@example.test'],
        Role::Owner,
    );
    config()->set('services.google.client_id', 'google-client-id');

    $verifier = Mockery::mock(GoogleIdentityVerifier::class);
    $verifier->shouldReceive('verify')
        ->once()
        ->with('signed-google-token')
        ->andReturn([
            'sub' => 'google-owner-subject',
            'email' => 'owner@example.test',
            'name' => 'Google Name',
        ]);
    app()->instance(GoogleIdentityVerifier::class, $verifier);

    $this->withHeader('X-Tenant', (string) $tenant->getKey())
        ->postJson('/api/v1/auth/google', ['credential' => 'signed-google-token'])
        ->assertOk()
        ->assertJsonPath('data.user.id', $owner->getKey())
        ->assertJsonPath('data.user.name', 'Existing Owner')
        ->assertJsonPath('data.role', 'owner');

    expect(UserIdentity::query()
        ->where('provider', 'google')
        ->where('user_id', $owner->getKey())
        ->exists())->toBeTrue();
});
