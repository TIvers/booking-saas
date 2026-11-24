# Архитектура

<!--
ОПИСАНИЕ ФАЙЛА: обзор архитектуры системы.
- Схема компонентов (SPA, API, Postgres, Redis, Horizon, Reverb, Stripe, Telegram)
- Слои backend: Controller -> Action -> Service/Model, события и слушатели
- Поток записи: выбор слота -> резерв -> оплата -> вебхук -> подтверждение -> уведомления
- Жизненный цикл записи (pending -> confirmed -> completed / cancelled / no_show)
- Что бы изменил при росте нагрузки (outbox, CQRS для аналитики, шардирование)
-->
