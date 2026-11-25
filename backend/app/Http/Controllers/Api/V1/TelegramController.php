<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Jobs\SendTelegramBroadcast;
use App\Models\Membership;
use App\Models\TelegramMarketingSubscription;
use App\Models\TelegramSubscription;
use App\Models\TelegramSubscriptionToken;
use App\Models\Tenant;
use App\Models\TenantTelegramBot;
use App\Models\UserIdentity;
use App\Services\Telegram\TelegramBotApi;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class TelegramController
{
    public function bot(Request $request): JsonResponse
    {
        $this->ensureOwner($request);
        $bot = TenantTelegramBot::query()->first();
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');

        return response()->json([
            'data' => [
                'connected' => $bot !== null,
                'username' => $bot?->bot_username,
                'webhook_ready' => $bot?->webhook_ready ?? false,
                'service_link_template' => $bot === null
                    ? null
                    : "https://t.me/{$bot->bot_username}?startapp=service-{service_id}",
                'mini_app_url' => rtrim((string) config('app.url'), '/')
                    ."/t/{$tenant->slug}?tg_bot=tenant",
                'company_description' => $bot?->company_description,
                'contact_url' => $bot?->contact_url,
                'marketing_subscribers' => TelegramMarketingSubscription::query()->count(),
            ],
        ]);
    }

    public function connectBot(Request $request, TelegramBotApi $telegramApi): JsonResponse
    {
        $this->ensureOwner($request);
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255', 'regex:/^[0-9]+:[A-Za-z0-9_-]{20,}$/'],
        ]);
        $token = $data['token'];
        $botInfo = $telegramApi->call($token, 'getMe')->json('result');

        if (
            ! is_array($botInfo)
            || ! isset($botInfo['id'], $botInfo['username'])
            || ! is_numeric($botInfo['id'])
            || ! is_string($botInfo['username'])
        ) {
            throw ValidationException::withMessages([
                'token' => ['Telegram did not return valid bot details for this token.'],
            ]);
        }

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        $tenantId = (int) $tenant->getKey();
        $existing = TenantTelegramBot::query()->first();
        if ($existing !== null && $existing->bot_id !== (int) $botInfo['id']) {
            throw ValidationException::withMessages([
                'token' => ['Disconnect the current bot before connecting a different bot.'],
            ]);
        }

        $secret = Str::random(48);
        try {
            $bot = TenantTelegramBot::query()->updateOrCreate(
                ['tenant_id' => $tenantId],
                [
                    'bot_id' => (int) $botInfo['id'],
                    'bot_username' => $botInfo['username'],
                    'bot_token' => $token,
                    'webhook_secret_hash' => hash('sha256', $secret),
                    'webhook_ready' => false,
                ],
            );
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? (string) $exception->getCode()) !== '23505') {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'token' => ['This Telegram bot is already connected to another organization.'],
            ]);
        }

        $this->configureBot($bot, $tenant, $telegramApi);
        $telegramApi->call($token, 'setWebhook', [
            'url' => $this->webhookUrl($tenant),
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query'],
        ]);
        $bot->update(['webhook_ready' => true]);

        return response()->json([
            'data' => [
                'connected' => true,
                'username' => $bot->bot_username,
                'webhook_ready' => $bot->webhook_ready,
                'mini_app_url' => rtrim((string) config('app.url'), '/')
                    ."/t/{$tenant->slug}?tg_bot=tenant",
                'company_description' => $bot->company_description,
                'contact_url' => $bot->contact_url,
                'marketing_subscribers' => TelegramMarketingSubscription::query()->count(),
            ],
        ]);
    }

    public function updateBotProfile(Request $request, TelegramBotApi $telegramApi): JsonResponse
    {
        $this->ensureOwner($request);
        $data = $request->validate([
            'company_description' => ['present', 'nullable', 'string', 'max:512'],
            'contact_url' => ['present', 'nullable', 'url', 'max:2048'],
        ]);
        $bot = TenantTelegramBot::query()->where('webhook_ready', true)->firstOrFail();
        $bot->update($data);

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        $this->configureBot($bot, $tenant, $telegramApi);

        return response()->json([
            'data' => [
                'company_description' => $bot->company_description,
                'contact_url' => $bot->contact_url,
            ],
        ]);
    }

    public function refreshBot(Request $request, TelegramBotApi $telegramApi): JsonResponse
    {
        $this->ensureOwner($request);
        $bot = TenantTelegramBot::query()->firstOrFail();
        $secret = Str::random(48);

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('tenant');
        $this->configureBot($bot, $tenant, $telegramApi);
        $telegramApi->call($bot->bot_token, 'setWebhook', [
            'url' => $this->webhookUrl($tenant),
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'callback_query'],
        ]);
        $bot->update([
            'webhook_secret_hash' => hash('sha256', $secret),
            'webhook_ready' => true,
        ]);

        return response()->json([
            'data' => [
                'updated' => true,
                'username' => $bot->bot_username,
                'webhook_ready' => $bot->webhook_ready,
            ],
        ]);
    }

    public function broadcast(Request $request): JsonResponse
    {
        $this->ensureOwner($request);
        $data = $request->validate([
            'message' => ['required', 'string', 'max:4096'],
        ]);
        abort_unless(
            TenantTelegramBot::query()->where('webhook_ready', true)->exists(),
            Response::HTTP_CONFLICT,
            'Connect a Telegram bot before sending a broadcast.',
        );

        $tenantId = (int) $request->attributes->get('tenant')->getKey();
        $queued = 0;
        TelegramMarketingSubscription::query()
            ->select(['id', 'chat_id'])
            ->orderBy('id')
            ->chunkById(500, function ($subscriptions) use ($tenantId, $data, &$queued): void {
                foreach ($subscriptions as $subscription) {
                    SendTelegramBroadcast::dispatch(
                        $tenantId,
                        (int) $subscription->chat_id,
                        $data['message'],
                    )->delay(now()->addSeconds(intdiv($queued, 20)))->afterCommit();
                    $queued++;
                }
            });

        return response()->json(['data' => ['queued' => $queued]], Response::HTTP_ACCEPTED);
    }

    public function disconnectBot(Request $request, TelegramBotApi $telegramApi): Response
    {
        $this->ensureOwner($request);
        $bot = TenantTelegramBot::query()->firstOrFail();
        $telegramApi->call($bot->bot_token, 'deleteWebhook', ['drop_pending_updates' => true]);
        $telegramApi->call($bot->bot_token, 'setChatMenuButton', [
            'menu_button' => ['type' => 'default'],
        ]);
        $telegramApi->call($bot->bot_token, 'setMyCommands', ['commands' => []]);
        $bot->delete();
        TelegramSubscription::query()->delete();
        TelegramSubscriptionToken::query()->delete();
        TelegramMarketingSubscription::query()->delete();

        return response()->noContent();
    }

    public function workerConfig(Request $request, int $tenant): JsonResponse
    {
        $bot = $this->workerBot($request, $tenant);
        /** @var Tenant $tenantModel */
        $tenantModel = $request->attributes->get('tenant');
        $chatId = $request->query('chat_id');

        return response()->json([
            'data' => [
                'bot_token' => $bot->bot_token,
                'bot_username' => $bot->bot_username,
                'tenant_name' => $tenantModel->name,
                'company_description' => $bot->company_description,
                'contact_url' => $bot->contact_url,
                'mini_app_url' => rtrim((string) config('app.url'), '/')
                    ."/t/{$tenantModel->slug}?tg_bot=tenant",
                'api_base' => config('services.telegram.api_base'),
                'marketing_subscribed' => is_numeric($chatId)
                    && TelegramMarketingSubscription::query()->where('chat_id', (int) $chatId)->exists(),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function updateMarketingSubscription(Request $request, int $tenant): JsonResponse
    {
        $this->workerBot($request, $tenant);
        $data = $request->validate([
            'chat_id' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(['subscribe', 'unsubscribe'])],
        ]);

        if ($data['action'] === 'subscribe') {
            TelegramMarketingSubscription::query()->updateOrCreate(
                ['chat_id' => (int) $data['chat_id']],
                ['subscribed_at' => now()],
            );
        } else {
            TelegramMarketingSubscription::query()
                ->where('chat_id', (int) $data['chat_id'])
                ->delete();
        }

        return response()->json(['data' => ['subscribed' => $data['action'] === 'subscribe']]);
    }

    public function subscription(Request $request): JsonResponse
    {
        $this->ensureCustomer($request);
        $subscription = TelegramSubscription::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->first();

        return response()->json([
            'data' => [
                'bot_configured' => TenantTelegramBot::query()->where('webhook_ready', true)->exists(),
                'telegram_linked' => UserIdentity::query()
                    ->where('user_id', $request->user()->getAuthIdentifier())
                    ->where('provider', 'telegram')
                    ->exists(),
                'connected' => $subscription?->enabled ?? false,
                'remind_24h' => $subscription?->remind_24h ?? true,
                'remind_2h' => $subscription?->remind_2h ?? true,
            ],
        ]);
    }

    public function connectNotifications(Request $request): JsonResponse
    {
        $this->ensureCustomer($request);
        $userId = (int) $request->user()->getAuthIdentifier();

        if (! UserIdentity::query()->where('user_id', $userId)->where('provider', 'telegram')->exists()) {
            throw ValidationException::withMessages([
                'telegram' => ['Link your Telegram account before enabling notifications.'],
            ]);
        }

        $bot = TenantTelegramBot::query()->where('webhook_ready', true)->first();
        if ($bot === null) {
            return response()->json(['message' => 'Telegram notifications are not configured.'], 409);
        }

        $rawToken = Str::random(48);
        TelegramSubscriptionToken::query()
            ->where('user_id', $userId)
            ->delete();
        TelegramSubscriptionToken::query()->create([
            'user_id' => $userId,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addMinutes(15),
        ]);

        return response()->json([
            'data' => [
                'url' => "https://t.me/{$bot->bot_username}?start={$rawToken}",
            ],
        ]);
    }

    public function updateSubscription(Request $request): JsonResponse
    {
        $this->ensureCustomer($request);
        $data = $request->validate([
            'remind_24h' => ['required', 'boolean'],
            'remind_2h' => ['required', 'boolean'],
        ]);
        $subscription = TelegramSubscription::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->firstOrFail();
        $subscription->update($data);

        return response()->json([
            'data' => [
                'connected' => $subscription->enabled,
                'remind_24h' => $subscription->remind_24h,
                'remind_2h' => $subscription->remind_2h,
            ],
        ]);
    }

    public function disconnectNotifications(Request $request): Response
    {
        $this->ensureCustomer($request);
        TelegramSubscription::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->delete();

        return response()->noContent();
    }

    public function webhook(
        Request $request,
        int $tenant,
        TelegramBotApi $telegramApi,
    ): Response {
        abort_unless(
            (int) $request->attributes->get('tenant')->getKey() === $tenant,
            Response::HTTP_NOT_FOUND,
        );
        $bot = TenantTelegramBot::query()->first();
        $providedSecret = $request->header('X-Telegram-Bot-Api-Secret-Token');

        abort_if(
            $bot === null
            || ! is_string($providedSecret)
            || ! hash_equals($bot->webhook_secret_hash, hash('sha256', $providedSecret)),
            Response::HTTP_FORBIDDEN,
        );

        $message = $request->input('message');
        if (! is_array($message) || ! isset($message['chat']['id'])) {
            return response()->noContent();
        }

        $chatId = (int) $message['chat']['id'];
        $text = is_string($message['text'] ?? null) ? $message['text'] : '';

        if (preg_match('/^\\/start(?:@\\w+)?\\s+([A-Za-z0-9_-]{32,64})$/', $text, $matches) === 1) {
            $fromId = (int) ($message['from']['id'] ?? 0);
            $activated = $this->activateSubscription($matches[1], $chatId, $fromId);
            $telegramApi->call($bot->bot_token, 'sendMessage', [
                'chat_id' => $chatId,
                'text' => $activated
                    ? 'Уведомления о записях подключены. Изменить настройки можно в личном кабинете.'
                    : 'Не удалось подключить уведомления. Создайте новую ссылку в личном кабинете и откройте её из привязанного Telegram-аккаунта.',
            ]);
        } elseif (preg_match('/^\\/stop(?:@\\w+)?$/', $text) === 1) {
            TelegramSubscription::query()->where('chat_id', $chatId)->delete();
            TelegramMarketingSubscription::query()->where('chat_id', $chatId)->delete();
            $telegramApi->call($bot->bot_token, 'sendMessage', [
                'chat_id' => $chatId,
                'text' => 'Уведомления о записях и новости отключены.',
            ]);
        }

        return response()->noContent();
    }

    private function activateSubscription(string $rawToken, int $chatId, int $telegramUserId): bool
    {
        return DB::transaction(function () use ($rawToken, $chatId, $telegramUserId): bool {
            $token = TelegramSubscriptionToken::query()
                ->where('token_hash', hash('sha256', $rawToken))
                ->lockForUpdate()
                ->first();

            if ($token === null) {
                return false;
            }

            $identityMatches = UserIdentity::query()
                ->where('user_id', $token->user_id)
                ->where('provider', 'telegram')
                ->where('provider_subject', (string) $telegramUserId)
                ->exists();

            if (
                ! $identityMatches
                || $chatId !== $telegramUserId
            ) {
                return false;
            }

            if ($token->used_at !== null) {
                return TelegramSubscription::query()
                    ->where('user_id', $token->user_id)
                    ->where('chat_id', $chatId)
                    ->where('enabled', true)
                    ->exists();
            }
            if ($token->expires_at->isPast()) {
                return false;
            }

            TelegramSubscription::query()->updateOrCreate(
                ['user_id' => $token->user_id],
                [
                    'chat_id' => $chatId,
                    'enabled' => true,
                    'remind_24h' => true,
                    'remind_2h' => true,
                    'connected_at' => now(),
                ],
            );
            $token->update(['used_at' => now()]);

            return true;
        });
    }

    private function ensureOwner(Request $request): void
    {
        $membership = $this->membership($request);
        abort_unless($membership->role === Role::Owner, Response::HTTP_FORBIDDEN);
    }

    private function ensureCustomer(Request $request): void
    {
        $membership = $this->membership($request);
        abort_unless($membership->role === Role::Customer, Response::HTTP_FORBIDDEN);
    }

    private function membership(Request $request): Membership
    {
        return Membership::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->firstOrFail();
    }

    private function configureBot(
        TenantTelegramBot $bot,
        Tenant $tenant,
        TelegramBotApi $telegramApi,
    ): void {
        $description = trim((string) $bot->company_description);
        if ($description === '') {
            $description = "Онлайн-запись в {$tenant->name}.";
        }

        $telegramApi->call($bot->bot_token, 'setMyDescription', [
            'description' => mb_substr($description, 0, 512),
        ]);
        $telegramApi->call($bot->bot_token, 'setMyShortDescription', [
            'short_description' => mb_substr($description, 0, 120),
        ]);
        $telegramApi->call($bot->bot_token, 'setChatMenuButton', [
            'menu_button' => [
                'type' => 'web_app',
                'text' => 'Записаться',
                'web_app' => [
                    'url' => rtrim((string) config('app.url'), '/')
                        ."/t/{$tenant->slug}?tg_bot=tenant",
                ],
            ],
        ]);
        $telegramApi->call($bot->bot_token, 'setMyCommands', [
            'commands' => [
                ['command' => 'start', 'description' => 'Открыть меню и запись'],
                ['command' => 'about', 'description' => 'Информация об организации'],
                ['command' => 'stop', 'description' => 'Отключить уведомления'],
            ],
        ]);
    }

    private function webhookUrl(Tenant $tenant): string
    {
        return rtrim((string) config('services.telegram.api_base'), '/')
            ."/api/v1/telegram/python-webhook/{$tenant->getKey()}";
    }

    private function workerBot(Request $request, int $tenant): TenantTelegramBot
    {
        abort_unless(
            (int) $request->attributes->get('tenant')->getKey() === $tenant,
            Response::HTTP_NOT_FOUND,
        );
        $bot = TenantTelegramBot::query()->where('webhook_ready', true)->first();
        $providedSecret = $request->header('X-Telegram-Bot-Api-Secret-Token');

        abort_if(
            $bot === null
            || ! is_string($providedSecret)
            || ! hash_equals($bot->webhook_secret_hash, hash('sha256', $providedSecret)),
            Response::HTTP_FORBIDDEN,
        );

        return $bot;
    }
}
