<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\TelegramSubscription;
use App\Models\Tenant;
use App\Models\TenantTelegramBot;
use App\Services\Telegram\TelegramBotApi;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class SendTelegramNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $bookingId,
        public readonly int $tenantId,
        public readonly string $type,
        public readonly ?string $expectedStartsAt = null,
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
                $booking = Booking::query()
                    ->with(['service', 'staff.location'])
                    ->find($this->bookingId);
                $bot = TenantTelegramBot::query()->where('webhook_ready', true)->first();
                $subscription = $booking === null
                    ? null
                    : TelegramSubscription::query()
                        ->where('user_id', $booking->customer_id)
                        ->where('enabled', true)
                        ->first();

                if (
                    $booking === null
                    || $bot === null
                    || $subscription === null
                    || ! $this->isDeliverable($booking, $subscription)
                ) {
                    return null;
                }

                return [
                    'token' => $bot->bot_token,
                    'chat_id' => $subscription->chat_id,
                    'text' => $this->message($booking),
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
                'text' => $delivery['text'],
            ]);
        }
    }

    private function isDeliverable(Booking $booking, TelegramSubscription $subscription): bool
    {
        if (str_starts_with($this->type, 'reminder_')) {
            if (
                $booking->status !== BookingStatus::Confirmed
                || $this->expectedStartsAt === null
                || ! $booking->starts_at->equalTo(CarbonImmutable::parse($this->expectedStartsAt))
            ) {
                return false;
            }
            $reminderAt = $booking->starts_at->subHours($this->type === 'reminder_24h' ? 24 : 2);
            if ($reminderAt->isFuture() || $booking->starts_at->isPast()) {
                return false;
            }

            return $this->type === 'reminder_24h'
                ? $subscription->remind_24h
                : $subscription->remind_2h;
        }

        if ($this->type === 'booking_cancelled') {
            return $booking->status === BookingStatus::Cancelled;
        }

        if ($this->type === 'booking_confirmed') {
            return $booking->status === BookingStatus::Confirmed;
        }

        if ($this->type === 'booking_created' || $this->type === 'booking_rescheduled') {
            return in_array($booking->status, [BookingStatus::Pending, BookingStatus::Confirmed], true);
        }

        return false;
    }

    private function message(Booking $booking): string
    {
        $timezone = $booking->staff->location->timezone;
        $startsAt = $booking->starts_at->setTimezone($timezone)->format('d.m.Y H:i');
        $place = "{$booking->service->name}, {$booking->staff->display_name} — {$startsAt}";

        return match ($this->type) {
            'booking_created' => $booking->status === BookingStatus::Pending
                ? "Создан резерв записи: {$place}. Завершите подтверждение в личном кабинете."
                : "Вы записаны: {$place}.",
            'booking_confirmed' => "Запись подтверждена: {$place}.",
            'booking_cancelled' => $this->messageWithReason($booking, "Запись отменена: {$place}."),
            'booking_rescheduled' => $this->messageWithReason($booking, "Время записи изменено: {$place}."),
            'reminder_24h' => "Напоминание: завтра у вас запись — {$place}.",
            'reminder_2h' => "Напоминание: через 2 часа у вас запись — {$place}.",
            default => throw new \LogicException('Unknown Telegram notification type.'),
        };
    }

    private function messageWithReason(Booking $booking, string $message): string
    {
        $reason = $booking->changes()
            ->where('action', $this->type === 'booking_cancelled' ? 'cancelled' : 'rescheduled')
            ->latest('id')
            ->value('reason');

        return is_string($reason) && $reason !== ''
            ? $message."\nПричина: {$reason}"
            : $message;
    }
}
