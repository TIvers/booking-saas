<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\TelegramMarketingSubscription;
use App\Models\Tenant;
use App\Models\TenantTelegramBot;
use App\Services\Telegram\TelegramBotApi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class SendTelegramBroadcast implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(
        public readonly int $tenantId,
        public readonly int $chatId,
        public readonly string $message,
    ) {}

    public function handle(TelegramBotApi $telegramApi): void
    {
        $hadTenant = app()->bound('currentTenant');
        $previousTenant = $hadTenant ? app('currentTenant') : null;

        try {
            $delivery = DB::transaction(function (): ?array {
                DB::select(
                    "SELECT set_config('app.tenant_id', ?, true)",
                    [(string) $this->tenantId],
                );
                $tenant = Tenant::query()->find($this->tenantId);

                if ($tenant === null) {
                    return null;
                }

                app()->instance('currentTenant', $tenant);
                $bot = TenantTelegramBot::query()->where('webhook_ready', true)->first();
                $subscriptionExists = TelegramMarketingSubscription::query()
                    ->where('chat_id', $this->chatId)
                    ->exists();

                if ($bot === null || ! $subscriptionExists) {
                    return null;
                }

                return [
                    'token' => $bot->bot_token,
                    'chat_id' => $this->chatId,
                ];
            });
        } finally {
            if ($hadTenant) {
                app()->instance('currentTenant', $previousTenant);
            } else {
                app()->forgetInstance('currentTenant');
            }
        }

        if ($delivery !== null) {
            $telegramApi->call($delivery['token'], 'sendMessage', [
                'chat_id' => $delivery['chat_id'],
                'text' => $this->message,
            ]);
        }
    }
}
