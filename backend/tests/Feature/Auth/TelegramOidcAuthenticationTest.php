<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Membership;
use App\Models\UserIdentity;
use App\Services\Telegram\TelegramIdentityTokenVerifier;
use Illuminate\Foundation\Testing\DatabaseMigrations;
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
        ->andReturn(['sub' => '987654321', 'name' => 'Telegram Customer']);
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
        'provider_subject' => '1234567890',
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
        ->andReturn(['sub' => '1234567890', 'name' => 'Telegram Customer']);
    app()->instance(TelegramIdentityTokenVerifier::class, $verifier);

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/telegram/oidc', ['id_token' => 'signed-telegram-token'])
        ->assertOk()
        ->assertJsonPath('data.user.id', $customer->getKey())
        ->assertJsonPath('data.user.name', 'Existing Telegram Customer')
        ->assertJsonPath('data.role', 'customer');
});
