# Hotel Management System — Angular Frontend

Arabic/English SPA for the Hotel System API ([checking-Booking](https://github.com/AbdulroufOyoun/checking-Booking)). Uses **Laravel Reverb** for live updates on reservations, room board, and payments.

| | |
|---|---|
| **Stack** | Angular 21, Bootstrap 5, ngx-translate, ng-bootstrap, Laravel Echo + Reverb |
| **Backend** | [checking-Booking](https://github.com/AbdulroufOyoun/checking-Booking) on port **8001** |
| **WebSocket** | Reverb on port **8080** (must match backend `.env`) |
| **Active branch** | `upgrade/angular-21` |

---

## Prerequisites

| Tool | Version |
|------|---------|
| Node.js | 20+ (24 recommended) |
| npm | 10+ |
| MySQL | 8.x | Via backend setup |
| **Redis** | 6+ | Via backend setup (cache + queues) |
| Backend API | `http://127.0.0.1:8001/api/` |
| **Reverb** | `php artisan reverb:start` on port **8080** |

Set up the **full backend stack** first (MySQL, Redis, Reverb, queue worker) — see [backend README](../checking-Booking/README.md#first-time-setup-local).

---

## First-time setup (local)

### Overview

| Goal | Backend command | Frontend |
|------|-----------------|----------|
| **New empty hotel** | `php artisan migrate --seed` | `npm start` → login `001` / `admin123` |
| **Demo / QA dataset** | `php artisan demo:setup --fresh` | same |

### 1. Clone and install

**Monorepo** (this folder):

```bash
cd HotelManageSystemAngular
npm install
```

**Standalone repo:**

```bash
git clone https://github.com/AbdulroufOyoun/BookingSystemFront.git
cd BookingSystemFront
git checkout upgrade/angular-21
npm install
```

### 2. Backend essentials (before SPA)

In `checking-Booking/`:

1. Create MySQL databases (`checkinbooking` + `clientCheckinBooking` or your names).
2. Start Redis — e.g. `docker run -d --name hotel-redis -p 6379:6379 redis:7-alpine`
3. Copy `.env`, set `DB_*`, `REDIS_*`, `CACHE_DRIVER=redis`, `QUEUE_CONNECTION=redis`, `BROADCAST_DRIVER=reverb`
4. `php artisan key:generate`
5. `php artisan passport:keys`
6. `php artisan reverb:install` (if `REVERB_APP_KEY` is empty)
7. **Fresh install:** `php artisan migrate --seed`  
   **Demo only:** `php artisan demo:setup --fresh` then `php artisan demo:verify`
8. Start API (`php artisan serve --host=127.0.0.1 --port=8001`), Reverb (`php artisan reverb:start`), queue (`php artisan queue:work redis`)

Default seed creates admin user and lookup lists only — **no rooms or reservations**. Configure the property in the app after first login.

### 3. API URL and Reverb (must match backend `.env`)

Development settings are in `src/environments/environment.ts`:

```typescript
export const environment = {
  production: false,
  apiUrl: 'http://127.0.0.1:8001/api/',
  reverb: {
    key: 'YOUR_REVERB_APP_KEY',           // same as REVERB_APP_KEY in backend .env
    host: '127.0.0.1',
    port: 8080,
    scheme: 'http' as 'http' | 'https',
    authEndpoint: 'http://127.0.0.1:8001/api/broadcasting/auth',
    debounceMs: 400,
    fallbackPollMs: 0,
  },
};
```

| Angular `reverb` field | Backend `.env` |
|------------------------|----------------|
| `key` | `REVERB_APP_KEY` |
| `host` | `REVERB_HOST` (use `127.0.0.1` locally) |
| `port` | `REVERB_PORT` (default `8080`) |
| `scheme` | `REVERB_SCHEME` (`http` locally) |
| `authEndpoint` | `{APP_URL}/api/broadcasting/auth` |

After changing Reverb keys on the backend, update `environment.ts` and restart `ng serve`.

Production build uses `environment.prod.ts` with HTTPS/WSS values for your deployed API and Reverb proxy.

### 4. Start all dev processes

You need **four** running processes for the full experience:

| Terminal | Directory | Command |
|----------|-----------|---------|
| 1 | `checking-Booking` | `php artisan serve --host=127.0.0.1 --port=8001` |
| 2 | `checking-Booking` | `php artisan reverb:start` |
| 3 | `checking-Booking` | `php artisan queue:work redis --tries=3` |
| 4 | `HotelManageSystemAngular` | `npm start` |

Confirm API: `http://127.0.0.1:8001/api/users/setup-check`

### 5. Open the app

```bash
npm start
```

| | URL |
|---|---|
| Dev server | `http://127.0.0.1:4300` |
| Login page | `http://127.0.0.1:4300/#/auth` |

`npm start` runs `ng serve` on port **4300** (configured in `angular.json`).

Alternative port:

```bash
npx ng serve --host 127.0.0.1 --port=4200
```

If you change the port, update `FRONTEND_URL` in the backend `.env` for CORS.

### 6. Log in

| Field | Value |
|-------|--------|
| Job number | `001` |
| Password | `admin123` |

Login uses **job number**, not email.

### 7. Verify real-time (optional)

1. Open Reservations in **two browsers** (or one normal + one incognito), same date.
2. Record a payment or create a reservation in browser A.
3. Browser B should refresh within ~1s without manual reload.

If not: check Reverb terminal is running, browser devtools → Network → WS to `8080`, and `reverb.key` matches backend.

---

## Build for production

```bash
npm run build
```

Output: `dist/HotelManageSystemAngular/`

Deploy the `dist` folder to your web server. Update `environment.prod.ts` with production API URL and Reverb WSS settings (typically `scheme: 'https'`, port `443` behind reverse proxy).

---

## Tests

**Unit tests:**

```bash
npm run test:unit
```

**E2E (Playwright)** — requires backend on **8001**; Playwright can start both servers automatically:

```bash
npx playwright test
```

Finance smoke:

```bash
npx playwright test e2e/financials.spec.ts e2e/reports.spec.ts e2e/booking-flow.spec.ts
```

---

## Project structure

```
src/app/
  features/          # lazy-loaded modules (dashboard, booking, financials, reports, admin, auth)
  pages/             # route components
  services/          # API clients, realtime.service.ts (Echo + Reverb)
src/environments/    # apiUrl + reverb config (dev / prod)
src/assets/i18n/     # Arabic (ar.json) and English (en.json)
e2e/                 # Playwright specs
```

Reports are launched from **Reports hub** → `report-runner` (24 backend report slugs).

---

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| Login 404 / network error | Start backend on port **8001**; check `environment.ts` `apiUrl` |
| CORS error | Set backend `FRONTEND_URL` to `http://127.0.0.1:4300` (match your SPA port) |
| **No live updates** | Start `php artisan reverb:start`; align `environment.ts` `reverb` with backend `.env` |
| WebSocket fails immediately | Use `127.0.0.1` consistently (not mixed `localhost` / `127.0.0.1`); check firewall on port 8080 |
| Report export never completes | Backend needs `php artisan queue:work redis` + running Redis |
| Blank page after build | Ensure hash routing (`/#/auth`) and `.htaccess` in `src/` is deployed |
| Permission denied on routes | Re-login after backend `PermissionSeeder` runs |
| E2E failures | API on 8001, Redis + Reverb running; for E2E with data use `php artisan demo:setup --fresh` on backend |

---

## Recommended demo flow

1. Log in → Dashboard
2. Room board → date `2026-08-01`
3. Reservations → create / view booking (test real-time in a second tab)
4. Financials → August 2026 KPIs
5. Reports → overview, occupancy, accrual-revenue
6. Toggle Arabic (RTL) on booking and reports screens

---

## Related links

- [Backend README](../checking-Booking/README.md) — MySQL, Redis, Reverb, queue worker
- [DEMO_RUNBOOK.md](../DEMO_RUNBOOK.md) — monorepo demo guide
- Backend demo reference: [checking-Booking/DEMO.md](https://github.com/AbdulroufOyoun/checking-Booking/blob/upgrade/laravel-12/DEMO.md)
