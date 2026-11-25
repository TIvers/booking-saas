<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramBotApi
{
    public function call(string $token, string $method, array $payload = []): Response
    {
        $apiBase = rtrim((string) config('services.telegram.api_base'), '/');
        if ($apiBase === '') {
            throw new RuntimeException('Telegram Bot API base URL is not configured.');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(10)
                ->post("{$apiBase}/bot{$token}/{$method}", $payload);
        } catch (ConnectionException) {
            throw new RuntimeException('Unable to reach the Telegram Bot API.');
        }

        if (! $response->successful()) {
            throw new RuntimeException("Telegram Bot API returned HTTP {$response->status()}.");
        }

        if (! $response->json('ok')) {
            throw new RuntimeException(
                'Telegram API rejected the request: '.(string) $response->json('description', 'Unknown error'),
            );
        }

        return $response;
    }
}
