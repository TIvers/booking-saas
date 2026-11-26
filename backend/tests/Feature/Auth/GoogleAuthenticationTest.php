<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Membership;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Auth\GoogleIdentityVerifier;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
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

    $headers = [
        'Origin' => 'http://localhost',
        'X-Tenant' => (string) $tenant->getKey(),
    ];
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
        ->and(Membership::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('role', Role::Customer->value)
            ->count())->toBe(1);
});

it('keeps Google sign-in separate from an organization account with the same email', function () {
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

    $this->withHeaders([
        'Origin' => 'http://localhost',
        'X-Tenant' => (string) $tenant->getKey(),
    ])
        ->postJson('/api/v1/auth/google', ['credential' => 'signed-google-token'])
        ->assertOk()
        ->assertJsonPath('data.user.account_type', 'customer')
        ->assertJsonPath('data.user.email', 'owner@example.test')
        ->assertJsonPath('data.role', 'customer');

    $googleIdentity = UserIdentity::query()
        ->where('provider', 'google')
        ->firstOrFail();

    expect($googleIdentity->user_id)->not->toBe($owner->getKey())
        ->and(UserIdentity::query()
            ->where('provider', 'google')
            ->where('user_id', $owner->getKey())
            ->exists())->toBeFalse()
        ->and(User::query()
            ->where('email', 'owner@example.test')
            ->count())->toBe(2);
});

it('does not allow an organization account to link Google', function () {
    $tenant = TenantFixtures::createTenant('google-organization-link');
    $owner = TenantFixtures::createMember($tenant, [], Role::Owner);

    $this->actingAs($owner)
        ->withHeader('X-Tenant', (string) $tenant->getKey())
        ->postJson('/api/v1/auth/google/link', ['credential' => 'signed-google-token'])
        ->assertForbidden();
});

it('allows an authenticated member to link a Google account from their profile', function () {
    $tenant = TenantFixtures::createTenant('google-profile-link-tenant');
    $customer = TenantFixtures::createMember($tenant, [], Role::Customer);
    config()->set('services.google.client_id', 'google-client-id');

    $verifier = Mockery::mock(GoogleIdentityVerifier::class);
    $verifier->shouldReceive('verify')
        ->once()
        ->with('signed-google-token')
        ->andReturn([
            'sub' => 'google-profile-subject',
            'email' => 'different-google@example.test',
            'name' => 'Google Customer',
        ]);
    app()->instance(GoogleIdentityVerifier::class, $verifier);

    $this->actingAs($customer)
        ->withHeaders([
            'Origin' => 'http://localhost',
            'X-Tenant' => (string) $tenant->getKey(),
        ])
        ->postJson('/api/v1/auth/google/link', ['credential' => 'signed-google-token'])
        ->assertOk()
        ->assertJsonPath('data.linked', true);

    TenantFixtures::activate($tenant);
    expect(UserIdentity::query()
        ->where('provider', 'google')
        ->where('provider_subject', 'google-profile-subject')
        ->where('user_id', $customer->getKey())
        ->exists())->toBeTrue();
});

it('merges a Google-linked account into the current account', function () {
    $tenant = TenantFixtures::createTenant('google-profile-conflict-tenant');
    $customer = TenantFixtures::createMember(
        $tenant,
        ['email' => null, 'password_set_at' => null],
        Role::Customer,
    );
    $otherCustomer = TenantFixtures::createMember(
        $tenant,
        [
            'email' => null,
            'username' => 'source-login',
            'password' => 'source-passphrase-123',
            'password_set_at' => now(),
        ],
        Role::Customer,
    );
    TenantFixtures::activate($tenant);
    UserIdentity::query()->create([
        'user_id' => $otherCustomer->getKey(),
        'provider' => 'google',
        'provider_subject' => 'google-already-linked',
    ]);

    $verifier = Mockery::mock(GoogleIdentityVerifier::class);
    $verifier->shouldReceive('verify')
        ->once()
        ->with('signed-google-token')
        ->andReturn([
            'sub' => 'google-already-linked',
            'email' => 'customer@example.test',
            'name' => 'Google Customer',
        ]);
    app()->instance(GoogleIdentityVerifier::class, $verifier);

    $this->actingAs($customer)
        ->withHeaders([
            'Origin' => 'http://localhost',
            'X-Tenant' => (string) $tenant->getKey(),
        ])
        ->postJson('/api/v1/auth/google/link', ['credential' => 'signed-google-token'])
        ->assertOk()
        ->assertJsonPath('data.linked', true)
        ->assertJsonPath('data.merged', true)
        ->assertJsonPath('data.user.google_linked', true);

    TenantFixtures::activate($tenant);
    $mergedUser = User::query()->findOrFail($customer->getKey());
    expect(User::query()->whereKey($otherCustomer->getKey())->exists())->toBeFalse()
        ->and(UserIdentity::query()
            ->where('provider', 'google')
            ->where('provider_subject', 'google-already-linked')
            ->value('user_id'))->toBe($customer->getKey())
        ->and($mergedUser->username)->toBe('source-login')
        ->and(Hash::check('source-passphrase-123', $mergedUser->getAuthPassword()))->toBeTrue();
});

it('blocks merging accounts that both have password logins', function () {
    $tenant = TenantFixtures::createTenant('google-password-conflict-tenant');
    $customer = TenantFixtures::createMember($tenant, ['email' => null], Role::Customer);
    $otherCustomer = TenantFixtures::createMember($tenant, ['email' => null], Role::Customer);
    TenantFixtures::activate($tenant);
    UserIdentity::query()->create([
        'user_id' => $otherCustomer->getKey(),
        'provider' => 'google',
        'provider_subject' => 'google-password-conflict',
    ]);

    $verifier = Mockery::mock(GoogleIdentityVerifier::class);
    $verifier->shouldReceive('verify')
        ->once()
        ->with('signed-google-token')
        ->andReturn([
            'sub' => 'google-password-conflict',
            'email' => 'customer@example.test',
            'name' => 'Google Customer',
        ]);
    app()->instance(GoogleIdentityVerifier::class, $verifier);

    $this->actingAs($customer)
        ->withHeaders([
            'Origin' => 'http://localhost',
            'X-Tenant' => (string) $tenant->getKey(),
        ])
        ->postJson('/api/v1/auth/google/link', ['credential' => 'signed-google-token'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account');

    TenantFixtures::activate($tenant);
    expect(User::query()->whereKey($otherCustomer->getKey())->exists())->toBeTrue()
        ->and(UserIdentity::query()
            ->where('provider_subject', 'google-password-conflict')
            ->value('user_id'))->toBe($otherCustomer->getKey());
});
