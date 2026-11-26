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

На главной странице выберите клиентский или организационный вход. Клиенты
сохраняют прежние способы регистрации и входа: email/логин с паролем, Google
или Telegram. Владельцы, менеджеры и специалисты входят отдельно, по email
или логину и паролю; Google и Telegram для организационных аккаунтов недоступны.
Клиентский и организационный аккаунты независимы, поэтому у них могут совпадать
email и логин. Google-вход создаёт или находит только клиентский аккаунт.
При создании карточки специалиста владелец организации может привязать новый
организационный аккаунт с логином.

## Google

Создайте OAuth Client ID типа «Web application» в Google Cloud Console и
добавьте разрешённые JavaScript origins: `https://booking.iversdev.ru` и
origin локального сайта разработки. Секрет OAuth не требуется: вход проверяет
подпись Google ID token на backend. Серверу требуется исходящий HTTPS-доступ к
`www.googleapis.com` для получения сертификатов подписи.

Для production задайте GitHub Actions Variable `GOOGLE_CLIENT_ID`. Локально
укажите тот же Client ID в `backend/.env` как `GOOGLE_CLIENT_ID`. Пока Client ID
не задан, кнопки Google-входа и привязки аккаунта не показываются. Google можно
привязать к уже существующему аккаунту в разделе профиля «Привязанные аккаунты».

## Telegram

Основной бот открывает главную страницу приложения как Telegram Mini App. Для
его WebApp задайте URL `/` через Menu Button или Main Mini App в @BotFather и
добавьте необязательный GitHub Secret `TELEGRAM_MAIN_BOT_TOKEN`; deploy workflow передаст токен backend-сервису.
Без этого секрета основной сайт работает, но вход Telegram через основной бот
внутри Mini App будет недоступен. Вход Telegram в обычном браузере настраивается
отдельно через Login Widget.

Чтобы показывать вход через Telegram в обычном браузере, настройте Login Widget
основного бота в @BotFather. Добавьте Redirect URI
`https://booking.iversdev.ru/login` и Trusted Origin
`https://booking.iversdev.ru`. Для локальной разработки добавьте
`http://localhost:5173/login` и origin `http://localhost:5173`. Общая страница
входа позволяет настроить один Redirect URI для всех организаций. Client ID
задайте как GitHub Actions Variable `TELEGRAM_LOGIN_CLIENT_ID` и локально в
`backend/.env` с тем же именем. Backend проверяет подпись Telegram ID token,
его issuer, audience и одноразовый nonce. В Mini App продолжает работать
существующий вход через WebApp. Telegram можно привязать к аккаунту в профиле:
подтверждение проходит на общей странице `/login`, после чего пользователь
возвращается в профиль.

Для отдельного бота организации владелец вручную создаёт бота через @BotFather,
затем в кабинете бизнеса → Telegram сохраняет его token. Backend проверяет
бота, автоматически задаёт описание, команды и кнопку запуска Mini App, затем
направляет webhook в Python worker и шифрует token с помощью `APP_KEY`. Worker
запускается вместе с Docker при старте production-сервера. Создать новых
Telegram-ботов через Telegram Bot API нельзя. Для прямых ссылок `startapp`
настройте Main Mini App в @BotFather; в кабинете будут доступны ссылки на услуги.
Вызовы Bot API и входящие webhook отдельного бота проходят через Worker,
указанный в `TG_API_BASE`. Worker перенаправляет `/bot...` в Telegram Bot API,
а `/api/v1/telegram/python-webhook/{tenant}` — на `https://booking.iversdev.ru`,
сохраняя заголовок проверки webhook. Он также проксирует публичные ключи Telegram
Login: `/oauth/.well-known/jwks.json` направляется на
`https://oauth.telegram.org/.well-known/jwks.json`. Backend использует этот путь
для проверки входа и привязки Telegram, поэтому серверу не нужен прямой доступ
к `oauth.telegram.org`. Исходный код Worker находится в
`telegram-worker/cloudflare-proxy.js`; для этого изменения достаточно обновить
Worker и backend. Webhook бота обновлять не нужно. Запросы Bot API включают bot
token, поэтому используйте только доверенный proxy.

Клиент связывает сайт-аккаунт с Telegram из Mini App или создаёт клиентский
аккаунт через Telegram. Для уведомлений клиент отдельно нажимает «Подключить
Telegram» и подтверждает запуск бота. После согласия бот напоминает за 24 часа
и за 2 часа; отмена и перенос отправляются сразу. Подписка на новости компании
отдельная: рассылки получает только тот, кто явно включил их в боте. Команда
`/stop` отключает и новости, и уведомления о записях. Для доставки нужны
публичный HTTPS-домен и работающий Horizon queue worker.

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
4. Добавьте в GitHub Secrets: `APP_KEY`, `POSTGRES_PASSWORD`, `DB_APP_PASSWORD`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `DEMO_OWNER_EMAIL`, `DEMO_OWNER_PASSWORD`; для входа через Telegram Mini App также добавьте `TELEGRAM_MAIN_BOT_TOKEN`. Для Google-входа задайте Actions Variable `GOOGLE_CLIENT_ID`, для браузерного Telegram Login Widget — `TELEGRAM_LOGIN_CLIENT_ID`.
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
