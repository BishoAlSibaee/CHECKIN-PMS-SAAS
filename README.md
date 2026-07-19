# Hotel System API (checking-Booking)

Laravel **12** REST API for the Hotel PMS: reservations, folio, finance, reports, night audit, RBAC, and **real-time** updates via Laravel Reverb.

| | |
|---|---|
| **Stack** | PHP 8.2+, Laravel 12, Passport 12, Spatie Permission 6, **Redis**, **Laravel Reverb** |
| **Frontend** | Angular 21 SPA (`HotelManageSystemAngular` in monorepo, or [BookingSystemFront](https://github.com/AbdulroufOyoun/BookingSystemFront)) |
| **Active branch** | `upgrade/laravel-12` |

---

## Prerequisites

| Tool | Version | Notes |
|------|---------|--------|
| PHP | 8.2+ | Extensions: `pdo`, `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath` |
| Composer | 2.x | |
| MySQL | 8.x | XAMPP or standalone — **two** databases (see below) |
| **Redis** | 6+ | **Required** for cache + queues in normal dev (see setup) |
| Node.js | 20+ | Optional — only if you run the Angular SPA locally |
| Docker | optional | Easiest way to run Redis on Windows |

**What Redis and Reverb do**

| Service | Role |
|---------|------|
| **Redis** | Shared cache (room board, dashboard, collections summaries) and queue backend (report exports, background jobs) |
| **Laravel Reverb** | WebSocket server — live updates on reservations, room board, payments (SPA `RealtimeService`) |

Redis does **not** replace Reverb. You need both for the full local experience.

---

## Quick start — new install (empty hotel)

Use this for a **real customer** or clean local install (no demo reservations).

| Step | Backend (`checking-Booking`) | Frontend (`HotelManageSystemAngular`) |
|------|------------------------------|----------------------------------------|
| 1 | Create MySQL DBs, Redis, copy `.env`, `php artisan key:generate` | `npm install` |
| 2 | `php artisan passport:keys` | Set `apiUrl` + `reverb` in `environment.ts` |
| 3 | `php artisan reverb:install` (if keys empty) | — |
| 4 | `php artisan migrate --seed` | — |
| 5 | Start API `:8001`, Reverb `:8080`, `queue:work` | `npm start` → `:4300` |
| 6 | — | Open `http://127.0.0.1:4300/#/auth` → login `001` / `admin123` |

After login: add **property** (buildings, rooms) in the UI, then create bookings.

For **demo data** (finance testing): `php artisan demo:setup --fresh` instead of step 4 only.

---

## First-time setup (local)

### 1. Clone and install dependencies

```bash
git clone https://github.com/AbdulroufOyoun/checking-Booking.git
cd checking-Booking
git checkout upgrade/laravel-12
composer install
```

`composer install` already pulls `predis/predis` and `laravel/reverb`. No extra Composer packages are needed for a standard dev setup.

### 2. Start Redis

Pick **one** option.

#### Option A — Docker (recommended on Windows)

```bash
docker run -d --name hotel-redis -p 6379:6379 redis:7-alpine
```

Verify:

```bash
docker exec hotel-redis redis-cli ping
# PONG
```

#### Option B — Redis installed natively (Linux / macOS / Memurai on Windows)

Ensure Redis listens on `127.0.0.1:6379` and responds to `redis-cli ping`.

#### Option C — Minimal dev without Redis (not recommended)

If you cannot run Redis yet, use file cache and synchronous queues in `.env`:

```env
CACHE_DRIVER=file
QUEUE_CONNECTION=sync
```

Room-board cache will not be shared across workers, and report exports will run inline. **Real-time WebSocket still needs Reverb** (step 5).

### 3. Environment file

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` for local development (adjust database names to match your MySQL):

```env
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8001
FRONTEND_URL=http://127.0.0.1:4300

# Primary DB — reservations, rooms, users, accounting
DB_HOST=127.0.0.1
DB_DATABASE=checkinbooking
DB_USERNAME=root
DB_PASSWORD=

# Secondary DB — Client model (connection mysql2)
DB2_HOST=127.0.0.1
DB2_DATABASE=clientCheckinBooking
DB2_USERNAME=root
DB2_PASSWORD=

# Redis — cache + queues (predis works without the phpredis extension)
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DB=0
REDIS_CACHE_DB=1

# Real-time broadcasting
BROADCAST_DRIVER=reverb
```

Create **both** MySQL databases in phpMyAdmin (or CLI) before migrating.

### 4. Install Reverb credentials

If `REVERB_APP_ID`, `REVERB_APP_KEY`, and `REVERB_APP_SECRET` are empty in `.env`:

```bash
php artisan reverb:install
```

For local WebSocket, set (or confirm):

```env
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
```

Copy the same `REVERB_APP_KEY`, host, port, and scheme into the Angular `environment.ts` `reverb` block (see frontend README).

### 5. Passport keys (required once)

```bash
php artisan passport:keys
```

Without OAuth keys, login returns an error. Keys are stored in `storage/oauth-*.key` (not committed).

### 6. Database — fresh install (production / new customer)

**Default seed** (`migrate --seed`) loads **essentials only**:

| Seeded | Not seeded |
|--------|------------|
| Permissions, roles (admin, receptionist, manager, accountant) | Buildings, floors, rooms |
| Admin user `001` / `admin123` | Reservations, clients, pricing plans |
| Stay reasons, reservation sources, guest classifications | Chart of accounts, refund policies |
| Peak weekdays/months (lookup lists) | Demo journal / daily charges |

```bash
php artisan migrate --seed
```

Then open the SPA and log in (step 8). Use **Property** in the UI to add buildings, room types, and rooms before creating bookings.

**Optional — full demo** (~650 reservations, finance QA):

```bash
php artisan demo:setup --fresh
php artisan demo:verify
```

**Light demo** (essential + test scenarios, no bulk property):

```bash
php artisan demo:setup --fresh --light
```

**Optional finance seeders** (when enabling accounting/refunds without full demo):

```bash
php artisan db:seed --class=ChartOfAccountsSeeder
php artisan db:seed --class=RefundPolicySeeder
```

### 7. Start all dev processes

Open **four** terminals in `checking-Booking` (or use a process manager). Default ports:

| # | Command | Port | Purpose |
|---|---------|------|---------|
| 1 | `php artisan serve --host=127.0.0.1 --port=8001` | 8001 | REST API |
| 2 | `php artisan reverb:start` | 8080 | WebSocket (Reverb) |
| 3 | `php artisan queue:work redis --tries=3` | — | Report exports and other queued jobs |
| 4 | *(frontend)* `npm start` in `HotelManageSystemAngular` | 4300 | Angular SPA |

**Shortcut (Windows):** from monorepo root run `.\scripts\dev-start.ps1` — starts API, Reverb, and queue in separate windows.

**Benchmark:** `php scripts/local-benchmark.php` (API must be running on 8001).

API base: `http://127.0.0.1:8001/api/`

Health check: `GET http://127.0.0.1:8001/api/users/setup-check`

Login page: `http://127.0.0.1:4300/#/auth`

**Real-time note:** PMS live events (`HotelLiveUpdated`) broadcast immediately via Reverb (`ShouldBroadcastNow`). The queue worker is still required for **report export jobs** and other queued tasks — not optional if you use Reports → Export.

### 8. Demo login (after `migrate --seed`)

| Field | Value |
|-------|--------|
| Job number | `001` |
| Password | `admin123` |

Use the Angular SPA to log in (job number, not email).

After pulling permission changes, re-seed and log in again:

```bash
php artisan db:seed --class=PermissionSeeder
```

---

## Connect the frontend

In the monorepo, the SPA lives in `HotelManageSystemAngular/`. Standalone repo: [BookingSystemFront](https://github.com/AbdulroufOyoun/BookingSystemFront) branch `upgrade/angular-21`.

1. `npm install && npm start` → `http://127.0.0.1:4300/#/auth`
2. Ensure `src/environments/environment.ts`:
   - `apiUrl` → `http://127.0.0.1:8001/api/`
   - `reverb.key` → same as `REVERB_APP_KEY` in backend `.env`
   - `reverb.host` / `port` / `scheme` → match `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`
   - `reverb.authEndpoint` → `http://127.0.0.1:8001/api/broadcasting/auth`

Full frontend setup: [HotelManageSystemAngular/README.md](../HotelManageSystemAngular/README.md).

---

## Recommended demo dates

| Screen | Date / period |
|--------|----------------|
| Room board | `2026-08-01` |
| Financials / accrual reports | August 2026 |
| Cash box report | June 2026 |

---

## Tests and verification

PHPUnit uses an **isolated** database (`checkinbooking_test` via `phpunit.xml` / `.env.testing`) — never run the full suite against your dev `checkinbooking` database.

```bash
php artisan test --compact
php artisan demo:verify
php artisan reports:verify
```

Finance-focused:

```bash
php artisan test --compact --filter=Finance
php artisan qa:finance-full
```

API smoke script:

```bash
php scripts/client_api_test.php
```

---

## Production deployment (summary)

On the server after upload:

```bash
composer install --no-dev --optimize-autoloader
php artisan passport:keys --force
php artisan migrate --force
php artisan hotel:ensure-admin
php artisan config:cache
php artisan route:cache
```

Run **Redis**, **Reverb**, and **queue workers** under Supervisor (see `scripts/supervisor-reverb.conf`). Detailed VPS steps: [docs/SAVEO_DEPLOY_RUNBOOK.md](../docs/SAVEO_DEPLOY_RUNBOOK.md).

Or run the helper script: `bash scripts/server-fix.sh`

Set `APP_DEBUG=false`, strong `APP_KEY`, and change the admin password:

```bash
php artisan hotel:ensure-admin --password='YourSecurePassword'
```

Production `.env` highlights:

```env
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
BROADCAST_DRIVER=reverb
REDIS_CLIENT=phpredis   # preferred on Linux VPS if ext-redis is installed
SESSION_DRIVER=redis    # optional — shared sessions across PHP-FPM workers
```

---

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| Login fails / missing OAuth keys | `php artisan passport:keys` |
| CORS blocked from SPA | Set `FRONTEND_URL` in `.env` to match the SPA origin (include port, e.g. `http://127.0.0.1:4300`) |
| Login 404 from Angular | API not running on port **8001** |
| **No live updates** (second browser stale) | Start `php artisan reverb:start`; check `BROADCAST_DRIVER=reverb`; match `reverb` keys in Angular `environment.ts` |
| Reverb connection refused | Confirm port **8080** free; `REVERB_HOST=127.0.0.1` locally (not `localhost` in Angular if mixed with `127.0.0.1`) |
| **Report export stuck** | Start `php artisan queue:work redis`; confirm Redis running |
| **Direct PDF/Excel/CSV disabled** | Result set exceeds `REPORT_MAX_DIRECT_DOWNLOAD_ROWS` (default 40). Narrow filters or use **Email export** |
| `Connection refused` to Redis | Start Redis (`docker start hotel-redis` or native service); or temporarily `CACHE_DRIVER=file` + `QUEUE_CONNECTION=sync` |
| `Class "Redis" not found` with `REDIS_CLIENT=phpredis` | Use `REDIS_CLIENT=predis` (no PHP extension) or install `phpredis` |
| Empty GL / P&L reports | `php artisan accounting:backfill-journal` |
| Finance audit FAIL | `php artisan demo:setup --fresh` then `php artisan demo:verify` |
| `Table ... doesn't exist in engine` (MySQL **1932**) | `php artisan db:repair-orphaned-tables` (also runs via `php artisan migrate`). Then optionally `php artisan demo:reset-reservations` for demo bookings |
| PDO extension missing | Enable `pdo` and `pdo_mysql` in PHP (XAMPP: `php.ini`) |
| PHPUnit wiped dev reservations | Never point tests at `checkinbooking`; use default `phpunit.xml` (`checkinbooking_test`) |

---

## API contract

- Responses: `Success`, `SuccessData`, `Failed`, `Pagination` (MyHelper pattern).
- Auth: Laravel Passport Bearer token.
- RBAC: Spatie permissions on routes.

---

## Related docs

- [DEMO.md](DEMO.md) — backend demo reference
- [DEMO_RUNBOOK.md](../DEMO_RUNBOOK.md) — monorepo end-to-end demo
- [docs/SAVEO_DEPLOY_RUNBOOK.md](../docs/SAVEO_DEPLOY_RUNBOOK.md) — production Redis + Reverb
- [AGENTS.md](../AGENTS.md) — development commands (monorepo root)

---

## License

MIT (Laravel framework components). Application code: see repository owner.
