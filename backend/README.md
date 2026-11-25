# Backend

## Tenant-scoped API and authentication

The SPA uses Laravel Sanctum's stateful cookie authentication. Before calling
the API, the browser must request `/sanctum/csrf-cookie`; tenant-scoped API
requests send the organization's slug in the `X-Tenant-Slug` header. The
`POST /api/v1/onboarding` endpoint creates an organization and its owner before
a tenant context exists. Numeric `X-Tenant` remains supported for existing
integrations.

Initial endpoints:

- `POST /api/v1/onboarding` creates a tenant and its owner account from
  `organization_name`, `slug`, `timezone`, `currency`, `owner_name`, `email`,
  `password`, and `password_confirmation`.
- `GET /api/v1/tenant`
- `POST /api/v1/auth/register`
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/telegram` and `/api/v1/auth/telegram/register`
- `POST /api/v1/auth/telegram/link`
- `GET /api/v1/auth/me`
- `POST /api/v1/auth/logout`
- `PUT /api/v1/auth/username`

`GET /api/v1/tenant` includes the tenant's ISO 4217 `currency` code and the
`demo_payments_enabled` feature flag. The database defaults existing and new
tenants to `USD`; update `tenants.currency` to configure a different currency.
Service prices are stored in hundredths of the configured currency.

Customers register and sign in with a username unique within their organization;
email is optional and is not used for new-account sign-in. Owners and managers
continue to sign in by email. Existing customer and specialist accounts without
a username can temporarily sign in by email and are prompted to choose a
username. Telegram WebApp sign-in verifies the signed `init_data` on the backend, rejects
data older than five minutes, and uses the configured main-bot token or the
organization's connected bot token. Unlinked Telegram users can either sign in
to an existing customer account and link it or create a customer account.
Telegram identities are globally unique; an identity can receive a customer
membership when it opens another organization. Owner/manager accounts cannot
be linked for Telegram sign-in. Email verification and other external identity
providers are not enabled.

The main bot token is read from `TELEGRAM_MAIN_BOT_TOKEN`. An organization
owner can connect one manually-created bot under `PUT /api/v1/manage/telegram-bot`;
the API validates it with Telegram, stores the token encrypted, configures the
bot profile, Mini App menu button and webhook, and never returns the token to
the SPA. Telegram sends updates to the Python worker container, which starts
automatically with Docker; the Laravel webhook endpoint remains an internal
handoff for booking-notification commands. Worker configuration is returned
only after validation of the Telegram webhook secret.

Bot API requests use `TG_API_BASE` (default: `https://api.telegram.org`). The
production environment routes them through the configured trusted proxy; the
proxy receives bot tokens as part of Bot API request URLs. Marketing
broadcasts use a separate tenant-scoped subscription, distinct from booking
notification consent, and are queued through Horizon.

## Catalog and schedules

Tenant-scoped read endpoints expose locations, active services, and active
staff. Writes require an authenticated tenant owner or manager:

- `GET /api/v1/locations`, `POST /api/v1/locations`, `PATCH /api/v1/locations/{id}`
- `GET /api/v1/services`, `POST /api/v1/services`, `PATCH /api/v1/services/{id}`, `DELETE /api/v1/services/{id}`
- `GET /api/v1/staff`, `POST /api/v1/staff`, `PATCH /api/v1/staff/{id}`, `DELETE /api/v1/staff/{id}`
- `GET /api/v1/manage/services` and `GET /api/v1/manage/staff` include inactive records for managers.
- `PUT /api/v1/staff/{id}/account` lets the organization owner create a
  tenant-scoped specialist login for an unlinked team member.
- `GET /api/v1/staff/{id}/schedule`, `PUT /api/v1/staff/{id}/schedule`
- `PUT` or `DELETE /api/v1/staff/{id}/schedule/exceptions/{YYYY-MM-DD}`

The production workflow seeds the `studio-oliva` demo organization with a
location, sample services, one staff member, a weekly schedule, and an owner
account. Configure `DEMO_OWNER_EMAIL` and `DEMO_OWNER_PASSWORD` as GitHub
Secrets; the password is hashed before it is stored. The seeder runs after
migrations with the privileged `booking` database role and is safe to repeat.

Deleting a service or staff member deactivates it to preserve booking history.
Updating a weekly schedule replaces all work ranges and breaks; weekdays use
ISO numbering (Monday 1 through Sunday 7), and times are local to the staff
member's location in `HH:MM` format. Each break must fit within a work range
for the same day.

## Booking API

- `GET /api/v1/manage/dashboard` returns current-month booking counts, service
  and staff activity, and the next active bookings. The value summary is based
  on confirmed/completed service prices; it is not payment revenue.
- `GET /api/v1/slots?staff_id={id}&service_id={id}&date=YYYY-MM-DD` returns
  available intervals in UTC. The date is interpreted in the location timezone.
- Authenticated tenant members can create bookings with
  `POST /api/v1/bookings` using `staff_id`, `service_id`, and an ISO-8601
  `starts_at` copied from an available slot.
- Services without a deposit are confirmed immediately. A service with a deposit
  creates a pending reservation for `BOOKING_RESERVATION_TTL_MINUTES`; a tenant
  manager can confirm it through `POST /api/v1/bookings/{id}/confirm`.
- When `PAYMENTS_DEMO_ENABLED=true`, the booking owner can call
  `POST /api/v1/bookings/{id}/demo-payment` and then submit `outcome=succeeded`
  or `outcome=failed` to `/demo-payment/result`. Success confirms the booking;
  failure cancels it. This is a simulator only: it never charges a card and
  must not be treated as evidence of a real payment. It is disabled by default;
  the demo environment explicitly enables it.
- `GET /api/v1/bookings` lists the caller's bookings (or all tenant bookings for
  an owner/manager); `GET /api/v1/bookings/{id}` applies the same ownership
  rules. Customers can cancel or reschedule only their own bookings. Owners and
  managers can manage bookings across the tenant.
- `POST /api/v1/bookings/{id}/cancel` returns the retained deposit and amount
  due for refund. Customer cancellations are free until
  `BOOKING_FREE_CANCELLATION_HOURS` before the appointment; late cancellations
  may retain the paid deposit. The refund calculation excludes demo payments;
  no payment provider is called.
- `POST /api/v1/bookings/{id}/reschedule` accepts a new ISO-8601 `starts_at`.
  The old interval remains booked unless the new slot is accepted.
- A specialist can view the tenant calendar and booking list, but the API only
  returns bookings assigned to their linked staff record. They can cancel or
  reschedule only those bookings and can read, but not edit, their own schedule
  through `GET /api/v1/staff/me/schedule`. Optional cancellation and rescheduling
  reasons are recorded in booking history and shown to the customer and
  organization managers.
- Customers can query `GET /api/v1/telegram/subscription`, request a one-time
  notification deep link with `POST`, update 24-hour/2-hour reminder preferences
  with `PUT`, and opt out with `DELETE`. The customer must open the bot link and
  press Start; Telegram does not let bots message users before they initiate a
  conversation. Confirmation, cancellation, rescheduling, and enabled reminders
  are delivered through the organization's bot via the queue.

Overlapping reservations are rejected by PostgreSQL's exclusion constraint and
returned as HTTP 409. Deposit reservations enqueue a delayed, tenant-scoped job
to release them at expiry; booking attempts also clean up expired reservations
for that staff member, so a delayed queue worker cannot indefinitely hold a
slot.

Tenant middleware starts a database transaction, sets
`app.tenant_id` transaction-locally, and resolves the tenant before handling
the request. Eloquent tenant scopes provide an additional application-level
guard; PostgreSQL RLS is the database-level guard.

User rows become visible to RLS only after a tenant membership is created.
Registration therefore inserts the user without `RETURNING`, creates the
membership, and only then loads the user; preserve this order when changing the
registration flow.

## PostgreSQL roles

Production uses two roles:

- `booking` is the privileged migration/bootstrap role.
- `booking_app` is a non-superuser runtime role with no `BYPASSRLS` and no
  schema-creation privileges.

The deploy workflow provisions `booking_app` from the `DB_APP_PASSWORD`
repository secret, applies migrations as `booking`, and grants the runtime
role access to the resulting tables. Do not use the privileged role for app,
Horizon, scheduler, or Reverb containers.

## Checks

```bash
composer test
vendor/bin/pint --test
```

Feature tests require PostgreSQL and should run as a non-superuser so RLS is
actually enforced. The CI workflow creates a restricted `booking_app` role for
the test suite.
