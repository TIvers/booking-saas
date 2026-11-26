<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Mail\VerifyAccountEmail;
use App\Models\TenantTelegramBot;
use App\Models\UserIdentity;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Mail;
use Tests\Support\TenantFixtures;

uses(DatabaseMigrations::class);

function signedTelegramInitData(string $token, int $telegramId, int $authDate): string
{
    $data = [
        'auth_date' => (string) $authDate,
        'query_id' => 'AAEAAAE',
        'user' => json_encode([
            'id' => $telegramId,
            'first_name' => 'Telegram Customer',
            'username' => 'telegram_customer',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
    ksort($data, SORT_STRING);
    $checkString = collect($data)
        ->map(fn (string $value, string $key): string => $key.'='.$value)
        ->implode("\n");
    $secretKey = hash_hmac('sha256', $token, 'WebAppData', true);
    $data['hash'] = hash_hmac('sha256', $checkString, $secretKey);

    return http_build_query($data);
}

beforeEach(function (): void {
    config(['services.telegram.main_bot_token' => '123456789:'.str_repeat('a', 32)]);
});

it('registers a customer from valid Telegram WebApp authorization data', function () {
    Mail::fake();
    $tenant = TenantFixtures::createTenant('telegram-registration');
    $headers = [
        'Origin' => 'http://localhost',
        'X-Tenant' => (string) $tenant->getKey(),
    ];
    $initData = signedTelegramInitData(
        (string) config('services.telegram.main_bot_token'),
        123456789,
        time(),
    );

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/telegram', [
            'init_data' => $initData,
            'bot' => 'main',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'unlinked');

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/telegram/register', [
            'init_data' => $initData,
            'bot' => 'main',
        ])
        ->assertCreated()
        ->assertJsonPath('data.role', 'customer')
        ->assertJsonPath('data.user.email', null)
        ->assertJsonPath('data.user.username', null)
        ->assertJsonPath('data.user.telegram_linked', true);

    $verification = $this->withHeaders($headers)
        ->putJson('/api/v1/auth/email', [
            'email' => 'telegram-recovery@example.test',
            'verification_channel' => 'code',
        ])
        ->assertAccepted()
        ->assertJsonPath('data.email_verified', false);

    $message = Mail::sent(VerifyAccountEmail::class)->first();
    expect($message)->not->toBeNull();

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/email/verification/code', [
            'challenge_id' => $verification->json('data.verification.challenge_id'),
            'code' => $message->code,
        ])
        ->assertOk()
        ->assertJsonPath('data.user.email', 'telegram-recovery@example.test')
        ->assertJsonPath('data.user.email_verified', true);

    $this->withHeaders($headers)
        ->putJson('/api/v1/auth/password', [
            'password' => 'telegram-account-passphrase',
            'password_confirmation' => 'telegram-account-passphrase',
        ])
        ->assertOk()
        ->assertJsonPath('data.has_password', true);

    $this->withHeaders($headers)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.username', null)
        ->assertJsonPath('data.user.telegram_linked', true)
        ->assertJsonPath('data.user.has_password', true);
});

it('links Telegram to an existing customer account and signs it in', function () {
    $tenant = TenantFixtures::createTenant('telegram-link');
    $customer = TenantFixtures::createMember(
        $tenant,
        ['email' => 'telegram-customer@example.test'],
        Role::Customer,
    );
    $headers = [
        'Origin' => 'http://localhost',
        'X-Tenant' => (string) $tenant->getKey(),
    ];
    $initData = signedTelegramInitData(
        (string) config('services.telegram.main_bot_token'),
        987654321,
        time(),
    );

    $this->actingAs($customer)
        ->withHeaders($headers)
        ->postJson('/api/v1/auth/telegram/link', [
            'init_data' => $initData,
            'bot' => 'main',
        ])
        ->assertOk()
        ->assertJsonPath('data.linked', true);

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/telegram', [
            'init_data' => $initData,
            'bot' => 'main',
        ])
        ->assertOk()
        ->assertJsonPath('data.user.id', $customer->getKey())
        ->assertJsonPath('data.role', 'customer');
});

it('does not allow Telegram sign-in or linking for an organization account', function () {
    $tenant = TenantFixtures::createTenant('telegram-organization-account');
    $owner = TenantFixtures::createMember($tenant, [], Role::Owner);
    TenantFixtures::activate($tenant);
    UserIdentity::query()->create([
        'user_id' => $owner->getKey(),
        'provider' => 'telegram',
        'provider_subject' => '321654987',
    ]);

    $headers = [
        'Origin' => 'http://localhost',
        'X-Tenant' => (string) $tenant->getKey(),
    ];
    $initData = signedTelegramInitData(
        (string) config('services.telegram.main_bot_token'),
        321654987,
        time(),
    );

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/telegram', [
            'init_data' => $initData,
            'bot' => 'main',
        ])
        ->assertForbidden();

    $this->actingAs($owner)
        ->withHeaders($headers)
        ->postJson('/api/v1/auth/telegram/link', [
            'init_data' => $initData,
            'bot' => 'main',
        ])
        ->assertForbidden();
});

it('verifies Telegram authorization with the tenant bot when the launch URL omits its source', function () {
    $tenant = TenantFixtures::createTenant('telegram-tenant-bot');
    $tenantBotToken = '987654321:'.str_repeat('b', 32);
    TenantFixtures::activate($tenant);
    TenantTelegramBot::query()->create([
        'bot_id' => 987654321,
        'bot_username' => 'tenant_bot',
        'bot_token' => $tenantBotToken,
        'webhook_secret_hash' => hash('sha256', 'webhook-secret'),
        'webhook_ready' => true,
    ]);

    $this->withHeaders([
        'Origin' => 'http://localhost',
        'X-Tenant' => (string) $tenant->getKey(),
    ])
        ->postJson('/api/v1/auth/telegram', [
            'init_data' => signedTelegramInitData($tenantBotToken, 321654987, time()),
            'bot' => 'main',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'unlinked');
});

it('rejects expired Telegram WebApp authorization data', function () {
    $tenant = TenantFixtures::createTenant('telegram-expired');
    $this->withHeaders([
        'Origin' => 'http://localhost',
        'X-Tenant' => (string) $tenant->getKey(),
    ])
        ->postJson('/api/v1/auth/telegram', [
            'init_data' => signedTelegramInitData(
                (string) config('services.telegram.main_bot_token'),
                456789123,
                time() - 301,
            ),
            'bot' => 'main',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('init_data');
});
