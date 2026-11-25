<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\TenantTelegramBot;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;

class TelegramWebAppAuthenticator
{
    public function verify(string $initData, string $botSource): TelegramWebAppData
    {
        parse_str($initData, $data);
        $hash = $data['hash'] ?? null;

        if (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/i', $hash) !== 1) {
            $this->invalidData();
        }
        foreach ($data as $value) {
            if (! is_string($value)) {
                $this->invalidData();
            }
        }

        $botToken = $this->botToken($botSource);
        unset($data['hash']);
        ksort($data, SORT_STRING);
        $checkString = collect($data)
            ->map(fn (mixed $value, string $key): string => $key.'='.$value)
            ->implode("\n");
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $expectedHash = hash_hmac('sha256', $checkString, $secretKey);

        if (! hash_equals($expectedHash, $hash)) {
            $this->invalidData();
        }

        $authDate = filter_var($data['auth_date'] ?? null, FILTER_VALIDATE_INT);
        $maxAge = (int) config('services.telegram.init_data_max_age_seconds', 300);
        $now = time();

        if (
            $authDate === false
            || $authDate > $now + 30
            || $now - $authDate > $maxAge
        ) {
            $this->invalidData();
        }

        try {
            $user = json_decode($data['user'] ?? '', true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalidData();
        }

        $validated = Validator::make(['user' => $user], [
            'user' => ['required', 'array'],
            'user.id' => ['required', 'integer', 'min:1'],
            'user.first_name' => ['required', 'string', 'max:255'],
            'user.last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'user.username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'user.photo_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ])->validate()['user'];

        return new TelegramWebAppData(
            user: $validated,
            startParam: isset($data['start_param']) && is_string($data['start_param'])
                ? $data['start_param']
                : null,
        );
    }

    private function botToken(string $botSource): string
    {
        if ($botSource === 'main') {
            $token = config('services.telegram.main_bot_token');
        } elseif ($botSource === 'tenant') {
            $bot = TenantTelegramBot::query()->where('webhook_ready', true)->first();
            $token = $bot?->bot_token;
        } else {
            $token = null;
        }

        if (! is_string($token) || $token === '') {
            throw ValidationException::withMessages([
                'bot' => ['Telegram authentication is not configured for this organization.'],
            ]);
        }

        return $token;
    }

    private function invalidData(): never
    {
        throw ValidationException::withMessages([
            'init_data' => ['Telegram authorization data is invalid or expired. Reopen the Web App and try again.'],
        ]);
    }
}
