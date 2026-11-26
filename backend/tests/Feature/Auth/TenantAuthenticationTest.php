<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Mail\VerifyAccountEmail;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\TenantFixtures;

uses(DatabaseMigrations::class);

it('registers a customer inside the selected tenant', function () {
    Mail::fake();
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
        'verification_channel' => 'code',
    ]);

    $response->assertAccepted()
        ->assertJsonPath('data.status', 'verification_required')
        ->assertJsonPath('data.verification.email', 'customer@example.test');

    $message = Mail::sent(VerifyAccountEmail::class)->first();
    expect($message)->not->toBeNull();

    $headers = [
        'Origin' => 'http://localhost',
        'X-Tenant' => (string) $tenant->getKey(),
    ];
    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/email/verification/code', [
            'challenge_id' => $response->json('data.verification.challenge_id'),
            'code' => $message->code,
        ])
        ->assertOk()
        ->assertJsonPath('data.user.email', 'customer@example.test')
        ->assertJsonPath('data.user.username', 'test-customer')
        ->assertJsonPath('data.user.email_verified', true)
        ->assertJsonPath('data.user.has_password', true)
        ->assertJsonPath('data.role', 'customer');
});

it('requires email, global username, and password for local registration', function () {
    $tenant = TenantFixtures::createTenant('credentials-required-tenant');
    $headers = [
        'Origin' => 'http://localhost',
        'X-Tenant' => (string) $tenant->getKey(),
    ];

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/register', [
            'name' => 'Incomplete Customer',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['username', 'email']);
});

it('returns a fresh email verification challenge when an unverified registrant signs in', function () {
    Mail::fake();
    $tenant = TenantFixtures::createTenant('identifier-required-tenant');
    $headers = [
        'Origin' => 'http://localhost',
        'X-Tenant' => (string) $tenant->getKey(),
    ];

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/register', [
            'name' => 'Unverified Customer',
            'username' => 'unverified-customer',
            'email' => 'unverified@example.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'verification_channel' => 'code',
        ])
        ->assertAccepted();

    $response = $this->withHeaders($headers)
        ->postJson('/api/v1/auth/login', [
            'login' => 'unverified-customer',
            'password' => 'correct-horse-battery',
            'verification_channel' => 'code',
        ])
        ->assertAccepted()
        ->assertJsonPath('data.status', 'verification_required');

    expect(Mail::sent(VerifyAccountEmail::class))->toHaveCount(2)
        ->and($response->json('data.verification.channel'))->toBe('code');
});

it('verifies a desktop registration using the emailed link', function () {
    Mail::fake();
    $tenant = TenantFixtures::createTenant('link-registration-tenant');
    $headers = [
        'Origin' => 'http://localhost',
        'X-Tenant' => (string) $tenant->getKey(),
    ];

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/register', [
            'name' => 'Link Customer',
            'username' => 'link-customer',
            'email' => 'link-customer@example.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'verification_channel' => 'link',
        ])
        ->assertAccepted();

    $message = Mail::sent(VerifyAccountEmail::class)->first();
    expect($message)->not->toBeNull();
    parse_str((string) parse_url($message->verificationUrl, PHP_URL_QUERY), $query);

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/email/verification/link', [
            'challenge_id' => $query['challenge'] ?? null,
            'token' => $query['token'] ?? null,
        ])
        ->assertOk()
        ->assertJsonPath('data.user.email_verified', true)
        ->assertJsonPath('data.user.username', 'link-customer');
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
        ->postJson('/api/v1/auth/organization/login', [
            'login' => 'owner@example.test',
            'password' => 'correct-horse-battery',
        ])
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->getKey())
        ->assertJsonPath('data.user.account_type', 'organization')
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

it('allows identical email and username across customer and organization accounts', function () {
    $tenant = TenantFixtures::createTenant('split-account-login-tenant');
    $customer = TenantFixtures::createMember($tenant, [
        'email' => 'shared@example.test',
        'username' => 'shared-login',
        'password' => 'customer-password',
    ], Role::Customer);
    $organization = TenantFixtures::createMember($tenant, [
        'email' => 'shared@example.test',
        'username' => 'shared-login',
        'password' => 'organization-password',
    ], Role::Owner);
    $headers = [
        'Origin' => 'http://localhost',
        'X-Tenant' => (string) $tenant->getKey(),
    ];

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/login', [
            'login' => 'shared@example.test',
            'password' => 'customer-password',
        ])
        ->assertOk()
        ->assertJsonPath('data.user.id', $customer->getKey())
        ->assertJsonPath('data.user.account_type', 'customer')
        ->assertJsonPath('data.role', 'customer');

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/organization/login', [
            'login' => 'shared@example.test',
            'password' => 'organization-password',
        ])
        ->assertOk()
        ->assertJsonPath('data.user.id', $organization->getKey())
        ->assertJsonPath('data.user.account_type', 'organization')
        ->assertJsonPath('data.role', 'owner');

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/organization/login', [
            'login' => 'shared-login',
            'password' => 'organization-password',
        ])
        ->assertOk()
        ->assertJsonPath('data.user.id', $organization->getKey());

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/login', [
            'login' => 'shared-login',
            'password' => 'organization-password',
        ])
        ->assertUnprocessable();

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/organization/login', [
            'login' => 'shared-login',
            'password' => 'customer-password',
        ])
        ->assertUnprocessable();

    TenantFixtures::activate($tenant);
    DB::table('users')->where('id', $customer->getKey())->delete();
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
