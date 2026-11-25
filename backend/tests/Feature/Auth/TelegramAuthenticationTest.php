<?php

declare(strict_types=1);

use App\Enums\Role;
use Illuminate\Foundation\Testing\DatabaseMigrations;
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
        ->assertJsonPath('data.user.username', null);
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
