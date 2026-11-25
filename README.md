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
npm install
npm run dev
```

Откройте `/`, чтобы перейти к существующей организации по адресу или создать
новую. Страница записи организации доступна по адресу `/t/{slug}`; для демо
после заполнения каталога используется `/t/studio-oliva`.

Клиенты и специалисты входят по логину и паролю; владелец и менеджер —
по email и паролю. При создании карточки специалиста владелец организации
может привязать новый аккаунт с tenant-scoped логином.

## Telegram

Основной бот открывает главную страницу приложения как Telegram Mini App. Для
его WebApp задайте URL `/` через Menu Button или Main Mini App в @BotFather и
добавьте необязательный GitHub Secret `TELEGRAM_MAIN_BOT_TOKEN`; deploy workflow передаст токен backend-сервису.
Без этого секрета основной сайт работает, но вход Telegram через основной бот
будет недоступен.

Для отдельного бота организации владелец вручную создаёт бота через @BotFather,
затем в кабинете бизнеса → Telegram сохраняет его token. Backend проверяет
бота, настраивает webhook и шифрует token с помощью `APP_KEY`. Создать новых
Telegram-ботов через Telegram Bot API нельзя. В @BotFather настройте Main Mini
App URL вида `https://booking.iversdev.ru/t/{slug}?tg_bot=tenant`; в кабинете
будут доступны ссылки, которые открывают выбранную услугу.

Клиент связывает сайт-аккаунт с Telegram из Mini App или создаёт клиентский
аккаунт через Telegram. Для уведомлений клиент отдельно нажимает «Подключить
Telegram» и подтверждает запуск бота. После согласия бот напоминает за 24 часа
и за 2 часа; отмена и перенос отправляются сразу. Напоминания можно отключить
по отдельности, уведомления — полностью в личном кабинете или командой `/stop`.
Для доставки нужны публичный HTTPS-домен и работающий Horizon queue worker.

Демо-предоплата включена параметром `PAYMENTS_DEMO_ENABLED=true` в backend
окружении. Она позволяет подтвердить или отклонить тестовую оплату, но не
списывает деньги и не подключена к банку. Текущая production-конфигурация
включает только этот демонстрационный режим. Не используйте его как настоящую
оплату; перед подключением реального провайдера задайте
`PAYMENTS_DEMO_ENABLED=false`.

## Production-домен

- Основной домен: `https://booking.iversdev.ru`
- HTTPS и reverse proxy: системный Nginx на сервере
- Деплой: GitHub Actions на self-hosted runner с labels `self-hosted`, `linux`, `x64`, `booking-saas`

## Настройка production-сервера

1. Добавьте DNS A-запись `booking` на IP сервера с доменом `iversdev.ru`.
2. Установите Docker через [scripts/setup-self-hosted-runner.sh](./scripts/setup-self-hosted-runner.sh).
3. Зарегистрируйте GitHub Actions runner с labels `self-hosted,linux,x64,booking-saas`.
4. Добавьте в GitHub Secrets: `APP_KEY`, `POSTGRES_PASSWORD`, `DB_APP_PASSWORD`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `DEMO_OWNER_EMAIL`, `DEMO_OWNER_PASSWORD`; для входа через основной Telegram-бот также добавьте `TELEGRAM_MAIN_BOT_TOKEN`.
   `POSTGRES_PASSWORD` используется привилегированной ролью для миграций, а приложение подключается ограниченной ролью `booking_app`.
   Создайте `DB_APP_PASSWORD` как URL-safe пароль длиной не менее 32 символов, например локально командой `openssl rand -hex 32`, и сохраните только в GitHub Secrets.
   `DEMO_OWNER_EMAIL` и `DEMO_OWNER_PASSWORD` задают вход владельца демо-организации; пароль должен быть длиной не менее 12 символов (его можно сгенерировать `openssl rand -hex 32`). Не передавайте пароль в чат и не сохраняйте его в файлах репозитория.
   После миграций workflow идемпотентно создаст демо-организацию `studio-oliva`
   и заполнит её каталог. Другие организации можно подключать через форму на
   главной странице сайта; их страницы записи будут доступны по `/t/{slug}`.
5. На сервере из checkout репозитория установите конфиг host Nginx:

   ```bash
   sudo cp docker/nginx/host-booking.conf /etc/nginx/sites-available/booking.iversdev.ru
   sudo ln -sfn /etc/nginx/sites-available/booking.iversdev.ru /etc/nginx/sites-enabled/booking.iversdev.ru
   sudo nginx -t
   sudo systemctl reload nginx
   ```

6. Отправьте готовые изменения в `main`; workflow соберет приложение, настроит ограниченную роль БД, применит миграции и выполнит smoke test.

Production использует существующий PostgreSQL volume: настройка роли `booking_app` выполняется идемпотентно и не удаляет данные. Не запускайте `docker compose down -v`.
