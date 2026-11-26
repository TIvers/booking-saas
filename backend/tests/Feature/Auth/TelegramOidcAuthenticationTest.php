<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Membership;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Telegram\TelegramIdentityTokenVerifier;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\TenantFixtures;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    config()->set('services.telegram.login_client_id', '123456789');
});

it('creates a Telegram customer account after an unlinked browser sign-in', function () {
    $tenant = TenantFixtures::createTenant('telegram-oidc-registration');
    $headers = [
        'Origin' => 'http://localhost',
        'X-Tenant' => (string) $tenant->getKey(),
    ];

    $config = $this->withHeaders($headers)
        ->postJson('/api/v1/auth/telegram/oidc/config')
        ->assertOk()
        ->assertJsonPath('data.enabled', true);
    $nonce = $config->json('data.nonce');

    $verifier = Mockery::mock(TelegramIdentityTokenVerifier::class);
    $verifier->shouldReceive('verify')
        ->once()
        ->with('signed-telegram-token', $nonce)
        ->andReturn([
            'sub' => '1987654321',
            'telegram_id' => '987654321',
            'name' => 'Telegram Customer',
        ]);
    app()->instance(TelegramIdentityTokenVerifier::class, $verifier);

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/telegram/oidc', ['id_token' => 'signed-telegram-token'])
        ->assertOk()
        ->assertJsonPath('data.status', 'unlinked');

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/telegram/oidc/register')
        ->assertCreated()
        ->assertJsonPath('data.user.name', 'Telegram Customer')
        ->assertJsonPath('data.user.email', null)
        ->assertJsonPath('data.user.telegram_linked', true)
        ->assertJsonPath('data.role', 'customer');

    expect(UserIdentity::query()->where('provider', 'telegram')->count())->toBe(1)
        ->and(Membership::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('role', Role::Customer->value)
            ->count())->toBe(1);
});

it('signs in a linked customer through Telegram OIDC', function () {
    $tenant = TenantFixtures::createTenant('telegram-oidc-login');
    $customer = TenantFixtures::createMember(
        $tenant,
        ['name' => 'Existing Telegram Customer'],
        Role::Customer,
    );
    TenantFixtures::activate($tenant);
    UserIdentity::query()->create([
        'user_id' => $customer->getKey(),
        'provider' => 'telegram',
        'provider_subject' => '2234567890',
    ]);

    $headers = [
        'Origin' => 'http://localhost',
        'X-Tenant' => (string) $tenant->getKey(),
    ];
    $config = $this->withHeaders($headers)
        ->postJson('/api/v1/auth/telegram/oidc/config')
        ->assertOk();
    $nonce = $config->json('data.nonce');

    $verifier = Mockery::mock(TelegramIdentityTokenVerifier::class);
    $verifier->shouldReceive('verify')
        ->once()
        ->with('signed-telegram-token', $nonce)
        ->andReturn([
            'sub' => '2234567890',
            'telegram_id' => '1234567890',
            'name' => 'Telegram Customer',
        ]);
    app()->instance(TelegramIdentityTokenVerifier::class, $verifier);

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/telegram/oidc', ['id_token' => 'signed-telegram-token'])
        ->assertOk()
        ->assertJsonPath('data.user.id', $customer->getKey())
        ->assertJsonPath('data.user.name', 'Existing Telegram Customer')
        ->assertJsonPath('data.role', 'customer');

    TenantFixtures::activate($tenant);
    expect(UserIdentity::query()
        ->where('provider_subject', '1234567890')
        ->where('user_id', $customer->getKey())
        ->exists())->toBeTrue()
        ->and(UserIdentity::query()
            ->where('provider_subject', '2234567890')
            ->exists())->toBeFalse();
});

it('merges legacy OIDC and Telegram WebApp accounts for the same Telegram user', function () {
    $tenant = TenantFixtures::createTenant('telegram-oidc-webapp-merge');
    $webAppCustomer = TenantFixtures::createMember($tenant, ['email' => null], Role::Customer);
    $oidcCustomer = TenantFixtures::createMember($tenant, ['email' => null], Role::Customer);
    TenantFixtures::activate($tenant);
    UserIdentity::query()->create([
        'user_id' => $webAppCustomer->getKey(),
        'provider' => 'telegram',
        'provider_subject' => '876543210',
    ]);
    UserIdentity::query()->create([
        'user_id' => $oidcCustomer->getKey(),
        'provider' => 'telegram',
        'provider_subject' => '2876543210',
    ]);
    UserIdentity::query()->create([
        'user_id' => $oidcCustomer->getKey(),
        'provider' => 'google',
        'provider_subject' => 'google-from-old-oidc',
    ]);

    $headers = [
        'Origin' => 'http://localhost',
        'X-Tenant' => (string) $tenant->getKey(),
    ];
    $config = $this->withHeaders($headers)
        ->postJson('/api/v1/auth/telegram/oidc/config')
        ->assertOk();
    $nonce = $config->json('data.nonce');

    $verifier = Mockery::mock(TelegramIdentityTokenVerifier::class);
    $verifier->shouldReceive('verify')
        ->once()
        ->with('signed-telegram-token', $nonce)
        ->andReturn([
            'sub' => '2876543210',
            'telegram_id' => '876543210',
            'name' => 'Telegram Customer',
        ]);
    app()->instance(TelegramIdentityTokenVerifier::class, $verifier);

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/telegram/oidc', ['id_token' => 'signed-telegram-token'])
        ->assertOk()
        ->assertJsonPath('data.user.id', $webAppCustomer->getKey())
        ->assertJsonPath('data.user.google_linked', true);

    TenantFixtures::activate($tenant);
    expect(User::query()->whereKey($oidcCustomer->getKey())->exists())->toBeFalse()
        ->and((int) UserIdentity::query()
            ->where('provider', 'google')
            ->where('provider_subject', 'google-from-old-oidc')
            ->value('user_id'))->toBe($webAppCustomer->getKey())
        ->and(UserIdentity::query()
            ->where('provider_subject', '2876543210')
            ->exists())->toBeFalse();
});

it('allows an authenticated member to link Telegram from their profile', function () {
    $tenant = TenantFixtures::createTenant('telegram-oidc-profile-link');
    $customer = TenantFixtures::createMember($tenant, [], Role::Customer);
    $headers = [
        'Origin' => 'http://localhost',
        'X-Tenant' => (string) $tenant->getKey(),
    ];
    $config = $this->withHeaders($headers)
        ->postJson('/api/v1/auth/telegram/oidc/config')
        ->assertOk();
    $nonce = $config->json('data.nonce');

    $verifier = Mockery::mock(TelegramIdentityTokenVerifier::class);
    $verifier->shouldReceive('verify')
        ->once()
        ->with('signed-telegram-token', $nonce)
        ->andReturn([
            'sub' => '2555000111',
            'telegram_id' => '555000111',
            'name' => 'Profile Telegram',
        ]);
    app()->instance(TelegramIdentityTokenVerifier::class, $verifier);

    $this->actingAs($customer)
        ->withHeaders($headers)
        ->postJson('/api/v1/auth/telegram/oidc/link', ['id_token' => 'signed-telegram-token'])
        ->assertOk()
        ->assertJsonPath('data.linked', true);

    TenantFixtures::activate($tenant);
    expect(UserIdentity::query()
        ->where('provider', 'telegram')
        ->where('provider_subject', '555000111')
        ->where('user_id', $customer->getKey())
        ->exists())->toBeTrue();
});

it('merges a Telegram-linked account into the current account and transfers its data', function () {
    $tenant = TenantFixtures::createTenant('telegram-oidc-profile-conflict');
    $customer = TenantFixtures::createMember($tenant, ['email' => null], Role::Customer);
    $otherCustomer = TenantFixtures::createMember($tenant, ['email' => null], Role::Customer);
    TenantFixtures::activate($tenant);
    UserIdentity::query()->create([
        'user_id' => $otherCustomer->getKey(),
        'provider' => 'telegram',
        'provider_subject' => '555000222',
    ]);
    UserIdentity::query()->create([
        'user_id' => $otherCustomer->getKey(),
        'provider' => 'google',
        'provider_subject' => 'google-identity-to-transfer',
    ]);

    $otherTenant = TenantFixtures::createTenant('telegram-oidc-other-tenant');
    TenantFixtures::activate($otherTenant);
    Membership::create([
        'user_id' => $otherCustomer->getKey(),
        'role' => Role::Customer,
    ]);

    TenantFixtures::activate($tenant);
    $location = TenantFixtures::createLocation($tenant, 'Main');
    $serviceId = DB::table('services')->insertGetId([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Haircut',
        'duration_min' => 60,
        'buffer_min' => 0,
        'price_cents' => 5000,
        'deposit_cents' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $staffId = DB::table('staff')->insertGetId([
        'tenant_id' => $tenant->getKey(),
        'location_id' => $location->getKey(),
        'display_name' => 'Specialist',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $startsAt = now()->addDay();
    $bookingId = DB::table('bookings')->insertGetId([
        'tenant_id' => $tenant->getKey(),
        'staff_id' => $staffId,
        'service_id' => $serviceId,
        'customer_id' => $otherCustomer->getKey(),
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
        'status' => 'confirmed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $changeId = DB::table('booking_changes')->insertGetId([
        'tenant_id' => $tenant->getKey(),
        'booking_id' => $bookingId,
        'actor_user_id' => $otherCustomer->getKey(),
        'action' => 'rescheduled',
        'from_starts_at' => $startsAt,
        'to_starts_at' => $startsAt->copy()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $headers = [
        'Origin' => 'http://localhost',
        'X-Tenant' => (string) $tenant->getKey(),
    ];
    $config = $this->withHeaders($headers)
        ->postJson('/api/v1/auth/telegram/oidc/config')
        ->assertOk();
    $nonce = $config->json('data.nonce');

    $verifier = Mockery::mock(TelegramIdentityTokenVerifier::class);
    $verifier->shouldReceive('verify')
        ->once()
        ->with('signed-telegram-token', $nonce)
        ->andReturn([
            'sub' => '2555000222',
            'telegram_id' => '555000222',
            'name' => 'Other Telegram',
        ]);
    app()->instance(TelegramIdentityTokenVerifier::class, $verifier);

    $this->actingAs($customer)
        ->withHeaders($headers)
        ->postJson('/api/v1/auth/telegram/oidc/link', ['id_token' => 'signed-telegram-token'])
        ->assertOk()
        ->assertJsonPath('data.linked', true)
        ->assertJsonPath('data.merged', true)
        ->assertJsonPath('data.user.telegram_linked', true)
        ->assertJsonPath('data.user.google_linked', true);

    TenantFixtures::activate($tenant);
    expect(User::query()->whereKey($otherCustomer->getKey())->exists())->toBeFalse()
        ->and((int) UserIdentity::query()
            ->where('provider', 'telegram')
            ->where('provider_subject', '555000222')
            ->value('user_id'))->toBe($customer->getKey())
        ->and((int) UserIdentity::query()
            ->where('provider', 'google')
            ->where('provider_subject', 'google-identity-to-transfer')
            ->value('user_id'))->toBe($customer->getKey())
        ->and((int) DB::table('bookings')->where('id', $bookingId)->value('customer_id'))
        ->toBe($customer->getKey())
        ->and((int) DB::table('booking_changes')->where('id', $changeId)->value('actor_user_id'))
        ->toBe($customer->getKey())
        ->and(Membership::withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->whereIn('user_id', [$customer->getKey(), $otherCustomer->getKey()])
            ->count())->toBe(1);

    TenantFixtures::activate($otherTenant);
    expect(Membership::withoutGlobalScopes()
        ->where('tenant_id', $otherTenant->getKey())
        ->where('user_id', $customer->getKey())
        ->exists())->toBeTrue();
});

it('blocks merging accounts with different roles in the same organization', function () {
    $tenant = TenantFixtures::createTenant('telegram-oidc-role-conflict');
    $customer = TenantFixtures::createMember($tenant, ['email' => null], Role::Customer);
    $owner = TenantFixtures::createMember($tenant, ['email' => null], Role::Owner);
    TenantFixtures::activate($tenant);
    UserIdentity::query()->create([
        'user_id' => $owner->getKey(),
        'provider' => 'telegram',
        'provider_subject' => '555000333',
    ]);

    $headers = [
        'Origin' => 'http://localhost',
        'X-Tenant' => (string) $tenant->getKey(),
    ];
    $config = $this->withHeaders($headers)
        ->postJson('/api/v1/auth/telegram/oidc/config')
        ->assertOk();
    $nonce = $config->json('data.nonce');

    $verifier = Mockery::mock(TelegramIdentityTokenVerifier::class);
    $verifier->shouldReceive('verify')
        ->once()
        ->with('signed-telegram-token', $nonce)
        ->andReturn([
            'sub' => '2555000333',
            'telegram_id' => '555000333',
            'name' => 'Owner Telegram',
        ]);
    app()->instance(TelegramIdentityTokenVerifier::class, $verifier);

    $this->actingAs($customer)
        ->withHeaders($headers)
        ->postJson('/api/v1/auth/telegram/oidc/link', ['id_token' => 'signed-telegram-token'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account');

    TenantFixtures::activate($tenant);
    expect(User::query()->whereKey($owner->getKey())->exists())->toBeTrue()
        ->and((int) UserIdentity::query()
            ->where('provider_subject', '555000333')
            ->value('user_id'))->toBe($owner->getKey());
});
