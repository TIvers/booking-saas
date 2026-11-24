# Frontend

Vue 3 single-page application built with TypeScript and Vite.

## Routes

- `/` — public booking flow.
- `/login` — customer and manager sign-in or customer registration.
- `/my-bookings` — authenticated customer booking history, cancellation, and
  rescheduling.
- `/manage` — owner/manager dashboard, calendar, bookings, schedule, services,
  and staff management.

The customer booking and management routes are protected by role-aware router
guards. The API client sends the configured tenant ID and uses Sanctum cookie
authentication.

## Commands

```bash
npm run dev
npm run build
```
