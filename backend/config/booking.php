<?php

declare(strict_types=1);

return [
    // Шаг сетки слотов в минутах.
    'slot_step_minutes' => (int) env('BOOKING_SLOT_STEP_MINUTES', 15),

    // Минимальный срок до начала записи: ближе чем через столько минут записаться нельзя.
    'min_lead_minutes' => (int) env('BOOKING_MIN_LEAD_MINUTES', 120),

    // На сколько минут резервируется слот, пока клиент оплачивает депозит.
    'reservation_ttl_minutes' => (int) env('BOOKING_RESERVATION_TTL_MINUTES', 10),

    // За сколько часов до начала отправлять напоминания.
    'reminder_hours' => [24, 2],

    // Время жизни кэша слотов в секундах (дополнительно к инвалидации по событиям).
    'slots_cache_ttl' => (int) env('BOOKING_SLOTS_CACHE_TTL', 300),

    // Бесплатная отмена возможна не позже чем за столько часов до начала.
    'free_cancellation_hours' => (int) env('BOOKING_FREE_CANCELLATION_HOURS', 24),

    'demo_owner' => [
        'email' => env('DEMO_OWNER_EMAIL'),
        'password' => env('DEMO_OWNER_PASSWORD'),
    ],
];
