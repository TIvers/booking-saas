# Booking SaaS

Multi-tenant SaaS-платформа онлайн-записи с Laravel 12, PostgreSQL 16, Redis, Vue 3 и Docker.

## Локальный запуск

Скопируйте примеры окружения:

```bash
cp .env.example .env
cp backend/.env.example backend/.env
docker compose build
docker compose up -d postgres redis
docker compose run --rm --no-deps app composer install
docker compose run --rm --no-deps app php artisan key:generate
docker compose run --rm --no-deps app php artisan migrate --force
docker compose up -d
```

Для разработки frontend можно запускать отдельно:

```bash
cd frontend
cp .env.example .env.local
# Set VITE_TENANT_ID to the numeric tenant ID for the booking site.
npm install
npm run dev
```

## Production-домен

- Основной домен: `https://booking.iversdev.ru`
- HTTPS и reverse proxy: системный Nginx на сервере
- Деплой: GitHub Actions на self-hosted runner с labels `self-hosted`, `linux`, `x64`, `booking-saas`

## Настройка production-сервера

1. Добавьте DNS A-запись `booking` на IP сервера с доменом `iversdev.ru`.
2. Установите Docker через [scripts/setup-self-hosted-runner.sh](./scripts/setup-self-hosted-runner.sh).
3. Зарегистрируйте GitHub Actions runner с labels `self-hosted,linux,x64,booking-saas`.
4. Добавьте в GitHub Secrets: `APP_KEY`, `POSTGRES_PASSWORD`, `DB_APP_PASSWORD`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`.
   `POSTGRES_PASSWORD` используется привилегированной ролью для миграций, а приложение подключается ограниченной ролью `booking_app`.
   Создайте `DB_APP_PASSWORD` как URL-safe пароль длиной не менее 32 символов, например локально командой `openssl rand -hex 32`, и сохраните только в GitHub Secrets.
   Перед первой выкладкой создайте строку организации, чтобы получить числовой ID:

   ```bash
   sudo docker exec -it booking-saas-postgres-1 psql -U booking -d booking \
     -c "INSERT INTO tenants (name, slug, timezone, created_at, updated_at)
         VALUES ('Студия Олива', 'studio-oliva', 'Europe/Moscow', NOW(), NOW())
         ON CONFLICT (slug) DO NOTHING
         RETURNING id;"
   ```

   Если строка уже была создана, получите её ID запросом
   `SELECT id FROM tenants WHERE slug = 'studio-oliva';`.
   Задайте этот ID как GitHub Actions repository variable `VITE_TENANT_ID`.
   После миграций workflow идемпотентно заполнит демо-каталог и расписание.
5. На сервере из checkout репозитория установите конфиг host Nginx:

   ```bash
   sudo cp docker/nginx/host-booking.conf /etc/nginx/sites-available/booking.iversdev.ru
   sudo ln -sfn /etc/nginx/sites-available/booking.iversdev.ru /etc/nginx/sites-enabled/booking.iversdev.ru
   sudo nginx -t
   sudo systemctl reload nginx
   ```

6. Отправьте готовые изменения в `main`; workflow соберет приложение, настроит ограниченную роль БД, применит миграции и выполнит smoke test.

Production использует существующий PostgreSQL volume: настройка роли `booking_app` выполняется идемпотентно и не удаляет данные. Не запускайте `docker compose down -v`.
