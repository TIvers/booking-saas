<?php

declare(strict_types=1);

namespace App\Services\Telegram;

final readonly class TelegramWebAppData
{
    /**
     * @param  array{id: int, first_name: string, last_name?: string, username?: string, photo_url?: string}  $user
     */
    public function __construct(
        public array $user,
        public ?string $startParam,
    ) {}
}
