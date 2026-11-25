<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\TelegramSubscription;
use App\Models\TelegramSubscriptionToken;
use App\Models\TenantTelegramBot;
use App\Models\UserIdentity;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\TenantFixtures;

uses(DatabaseMigrations::class);

it('connects an organization bot and only subscribes the linked Telegram user', function () {
    $tenant = TenantFixtures::createTenant('telegram-bot-tenant');
    $owner = TenantFixtures::createMember($tenant, role: Role::Owner);
    $customer = TenantFixtures::createMember($tenant, role: Role::Customer);
    $botToken = '123456789:'.str_repeat('b', 32);
    $telegramId = 987654321;
    $webhookSecret = null;

    Http::fake(function (ClientRequest $request) use (&$webhookSecret): Response {
        if (str_ends_with($request->url(), '/getMe')) {
            return Http::response([
                'ok' => true,
                'result' => ['id' => 123456789, 'username' => 'tenant_booking_bot'],
            ]);
        }

        if (str_ends_with($request->url(), '/setWebhook')) {
            $webhookSecret = $request->data()['secret_token'];

            return Http::response(['ok' => true, 'result' => true]);
        }

        return Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
    });

    $this->actingAs($owner)
        ->withHeaders(['X-Tenant' => (string) $tenant->getKey()])
        ->putJson('/api/v1/manage/telegram-bot', ['token' => $botToken])
        ->assertOk()
        ->assertJsonPath('data.username', 'tenant_booking_bot')
        ->assertJsonMissing(['token' => $botToken]);

    TenantFixtures::activate($tenant);
    $bot = TenantTelegramBot::query()->firstOrFail();
    expect($bot->bot_token)->toBe($botToken)
        ->and($bot->getRawOriginal('bot_token'))->not->toBe($botToken)
        ->and($bot->webhook_ready)->toBeTrue();

    UserIdentity::query()->create([
        'user_id' => $customer->getKey(),
        'provider' => 'telegram',
        'provider_subject' => (string) $telegramId,
    ]);
    $rawLinkToken = Str::random(48);
    TelegramSubscriptionToken::query()->create([
        'user_id' => $customer->getKey(),
        'token_hash' => hash('sha256', $rawLinkToken),
        'expires_at' => now()->addMinutes(15),
    ]);

    expect($webhookSecret)->toBeString();
    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $webhookSecret)
        ->postJson("/api/v1/telegram/webhook/{$tenant->getKey()}", [
            'message' => [
                'chat' => ['id' => $telegramId, 'type' => 'private'],
                'from' => ['id' => $telegramId],
                'text' => "/start {$rawLinkToken}",
            ],
        ])
        ->assertNoContent();

    TenantFixtures::activate($tenant);
    expect(TelegramSubscription::query()->firstOrFail()->chat_id)->toBe($telegramId);
});
