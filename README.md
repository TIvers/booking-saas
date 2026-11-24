# Booking SaaS

Multi-tenant SaaS-платформа онлайн-записи с Laravel 12, PostgreSQL 16, Redis, Vue 3 и Docker.

## Локальный запуск

```bash
docker compose up -d --build
```

Основной адрес разработки определяется контейнером Caddy. Для разработки frontend можно запускать отдельно:

```bash
cd frontend
npm install
npm run dev
```

## Production-домен

- Основной домен: `https://booking.iversdev.ru`
- Автоматический HTTPS: Caddy
- Деплой: GitHub Actions на self-hosted runner с labels `self-hosted`, `linux`, `x64`, `booking-saas`

## Настройка production-сервера

1. Добавьте DNS A-запись `booking` на IP сервера с доменом `iversdev.ru`.
2. Установите Docker через [scripts/setup-self-hosted-runner.sh](./scripts/setup-self-hosted-runner.sh).
3. Зарегистрируйте GitHub Actions runner с labels `self-hosted,linux,x64,booking-saas`.
4. Добавьте в GitHub Secrets: `APP_KEY`, `POSTGRES_PASSWORD`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`.
5. Отправьте ветку `main`; workflow соберет и запустит приложение.
