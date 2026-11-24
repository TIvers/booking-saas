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

## Catalog and schedules

Tenant-scoped read endpoints expose locations, active services, and active
staff. Writes require an authenticated tenant owner or manager:

- `GET /api/v1/locations`, `POST /api/v1/locations`, `PATCH /api/v1/locations/{id}`
- `GET /api/v1/services`, `POST /api/v1/services`, `PATCH /api/v1/services/{id}`, `DELETE /api/v1/services/{id}`
- `GET /api/v1/staff`, `POST /api/v1/staff`, `PATCH /api/v1/staff/{id}`, `DELETE /api/v1/staff/{id}`
- `GET /api/v1/manage/services` and `GET /api/v1/manage/staff` include inactive records for managers.
- `GET /api/v1/staff/{id}/schedule`, `PUT /api/v1/staff/{id}/schedule`
- `PUT` or `DELETE /api/v1/staff/{id}/schedule/exceptions/{YYYY-MM-DD}`

Deleting a service or staff member deactivates it to preserve booking history.
Updating a weekly schedule replaces all work ranges and breaks; weekdays use
ISO numbering (Monday 1 through Sunday 7), and times are local to the staff
member's location in `HH:MM` format. Each break must fit within a work range
for the same day.

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
