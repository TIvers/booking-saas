<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Jobs\SendTelegramBroadcast;
use App\Models\TelegramMarketingSubscription;
use App\Models\TelegramSubscription;
use App\Models\TelegramSubscriptionToken;
use App\Models\TenantTelegramBot;
use App\Models\UserIdentity;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    Http::fake(function (ClientRequest $request) use (&$webhookSecret) {
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

    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/setWebhook')
        && $request->data()['url'] === rtrim((string) config('app.url'), '/')
            ."/api/v1/telegram/python-webhook/{$tenant->getKey()}"
        && in_array('callback_query', $request->data()['allowed_updates'], true));
    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/setChatMenuButton')
        && $request->data()['menu_button']['web_app']['url'] === rtrim((string) config('app.url'), '/')
            ."/t/{$tenant->slug}?tg_bot=tenant");

    $workerConfigUrl = "/api/v1/telegram/worker/config/{$tenant->getKey()}";
    $this->withHeader('X-Tenant', (string) $tenant->getKey())
        ->getJson($workerConfigUrl)
        ->assertForbidden();

    $this->withHeaders([
        'X-Tenant' => (string) $tenant->getKey(),
        'X-Telegram-Bot-Api-Secret-Token' => $webhookSecret,
    ])
        ->getJson($workerConfigUrl)
        ->assertOk()
        ->assertJsonPath('data.bot_token', $botToken)
        ->assertHeader('Cache-Control', 'no-store, private');

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

    $this->withHeaders([
        'X-Tenant' => (string) $tenant->getKey(),
        'X-Telegram-Bot-Api-Secret-Token' => $webhookSecret,
    ])
        ->postJson("/api/v1/telegram/worker/marketing/{$tenant->getKey()}", [
            'chat_id' => $telegramId,
            'action' => 'subscribe',
        ])
        ->assertOk()
        ->assertJsonPath('data.subscribed', true);

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

    $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => $webhookSecret])
        ->postJson("/api/v1/telegram/webhook/{$tenant->getKey()}", [
            'message' => [
                'chat' => ['id' => $telegramId, 'type' => 'private'],
                'from' => ['id' => $telegramId],
                'text' => '/stop',
            ],
        ])
        ->assertNoContent();

    TenantFixtures::activate($tenant);
    expect(TelegramSubscription::query()->where('user_id', $customer->getKey())->exists())
        ->toBeFalse()
        ->and(TelegramMarketingSubscription::query()->where('chat_id', $telegramId)->exists())
        ->toBeFalse();

    $this->actingAs($owner)
        ->withHeader('X-Tenant', (string) $tenant->getKey())
        ->deleteJson('/api/v1/manage/telegram-bot')
        ->assertNoContent();

    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/setChatMenuButton')
        && $request->data()['menu_button'] === ['type' => 'default']);
    Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/setMyCommands')
        && $request->data()['commands'] === []);
});

it('allows an owner to update bot details and queues broadcasts to opted-in chats', function () {
    $tenant = TenantFixtures::createTenant('telegram-broadcast-tenant');
    $owner = TenantFixtures::createMember($tenant, role: Role::Owner);

    Http::fake(function (ClientRequest $request) {
        if (str_ends_with($request->url(), '/getMe')) {
            return Http::response([
                'ok' => true,
                'result' => ['id' => 123456789, 'username' => 'tenant_booking_bot'],
            ]);
        }

        return Http::response(['ok' => true, 'result' => true]);
    });

    $headers = ['X-Tenant' => (string) $tenant->getKey()];
    $this->actingAs($owner)
        ->withHeaders($headers)
        ->putJson('/api/v1/manage/telegram-bot', ['token' => '123456789:'.str_repeat('c', 32)])
        ->assertOk();

    TenantFixtures::activate($tenant);
    TelegramMarketingSubscription::query()->create([
        'chat_id' => 1234,
        'subscribed_at' => now(),
    ]);

    $this->withHeaders($headers)
        ->putJson('/api/v1/manage/telegram-bot/profile', [
            'company_description' => 'Студия красоты',
            'contact_url' => 'https://studio.example',
        ])
        ->assertOk()
        ->assertJsonPath('data.company_description', 'Студия красоты')
        ->assertJsonPath('data.contact_url', 'https://studio.example');

    Queue::fake();
    $this->withHeaders($headers)
        ->postJson('/api/v1/manage/telegram-broadcasts', [
            'message' => 'Новая акция',
        ])
        ->assertAccepted()
        ->assertJsonPath('data.queued', 1);

    Queue::assertPushed(
        SendTelegramBroadcast::class,
        fn (SendTelegramBroadcast $job): bool => $job->tenantId === $tenant->getKey()
            && $job->chatId === 1234
            && $job->message === 'Новая акция',
    );
});
