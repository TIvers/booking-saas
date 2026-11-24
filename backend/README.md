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

Tenant middleware starts a database transaction, sets
`app.tenant_id` transaction-locally, and resolves the tenant before handling
the request. Eloquent tenant scopes provide an additional application-level
guard; PostgreSQL RLS is the database-level guard.

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
