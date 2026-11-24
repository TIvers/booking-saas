# Backend

## Tenant-scoped API and authentication

The SPA uses Laravel Sanctum's stateful cookie authentication. Before calling
the API, the browser must request `/sanctum/csrf-cookie`; tenant-scoped API
requests send the tenant's numeric ID in the `X-Tenant` header.

Initial endpoints:

- `GET /api/v1/tenant`
- `POST /api/v1/auth/register`
- `POST /api/v1/auth/login`
- `GET /api/v1/auth/me`
- `POST /api/v1/auth/logout`

`GET /api/v1/tenant` includes the tenant's ISO 4217 `currency` code and the
`demo_payments_enabled` feature flag. The database defaults existing and new
tenants to `USD`; update `tenants.currency` to configure a different currency.
Service prices are stored in hundredths of the configured currency.

## Catalog and schedules

Tenant-scoped read endpoints expose locations, active services, and active
staff. Writes require an authenticated tenant owner or manager:

- `GET /api/v1/locations`, `POST /api/v1/locations`, `PATCH /api/v1/locations/{id}`
- `GET /api/v1/services`, `POST /api/v1/services`, `PATCH /api/v1/services/{id}`, `DELETE /api/v1/services/{id}`
- `GET /api/v1/staff`, `POST /api/v1/staff`, `PATCH /api/v1/staff/{id}`, `DELETE /api/v1/staff/{id}`
- `GET /api/v1/manage/services` and `GET /api/v1/manage/staff` include inactive records for managers.
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
