<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Models\Membership;
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

        $tenantId = (int) $request->attributes->get('tenant')->getKey();
        $existing = TenantTelegramBot::query()->first();
        if ($existing !== null && $existing->bot_id !== (int) $botInfo['id']) {
            throw ValidationException::withMessages([
                'token' => ['Disconnect the current bot before connecting a different bot.'],
            ]);
        }

        $secret = Str::random(48);
        $webhookUrl = rtrim((string) config('app.url'), '/')
            ."/api/v1/telegram/webhook/{$tenantId}";

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

        $telegramApi->call($token, 'setWebhook', [
            'url' => $webhookUrl,
            'secret_token' => $secret,
            'allowed_updates' => ['message'],
        ]);
        $bot->update(['webhook_ready' => true]);

        return response()->json([
            'data' => [
                'connected' => true,
                'username' => $bot->bot_username,
                'webhook_ready' => $bot->webhook_ready,
            ],
        ]);
    }

    public function disconnectBot(Request $request, TelegramBotApi $telegramApi): Response
    {
        $this->ensureOwner($request);
        $bot = TenantTelegramBot::query()->firstOrFail();
        $telegramApi->call($bot->bot_token, 'deleteWebhook', ['drop_pending_updates' => true]);
        $bot->delete();
        TelegramSubscription::query()->delete();
        TelegramSubscriptionToken::query()->delete();

        return response()->noContent();
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
            $telegramApi->call($bot->bot_token, 'sendMessage', [
                'chat_id' => $chatId,
                'text' => 'Уведомления отключены.',
            ]);
        } elseif (preg_match('/^\\/start(?:@\\w+)?$/', $text) === 1) {
            $telegramApi->call($bot->bot_token, 'sendMessage', [
                'chat_id' => $chatId,
                'text' => 'Откройте онлайн-запись по ссылке организации, чтобы записаться или подключить уведомления.',
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
}
