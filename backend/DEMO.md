# Hotel System — Demo setup

Full runbook (ports, checklist, troubleshooting): **[../DEMO_RUNBOOK.md](../DEMO_RUNBOOK.md)**

## Prerequisites

- PHP 8.2+, Composer, MySQL (XAMPP)
- Node.js 18+ for Angular

## Backend (`checking-Booking`)

```bash
cd checking-Booking
composer install
cp .env.example .env
php artisan key:generate
# Configure DB_* in .env, then:
php artisan migrate:fresh --seed
php artisan demo:verify
php artisan serve --host=127.0.0.1 --port=8001
```

`migrate --seed` now includes:

- `ReservationTestDataSeeder` (2026 demo scenarios)
- `reservations:backfill-daily-charges --sync-base`
- `accounting:backfill-journal`

**Light seed** (skip ~650 bulk reservations): `php artisan demo:setup --fresh --light`

API base: **`http://127.0.0.1:8001/api/`** (use 8001 if port 8000 is another Laravel app)

### Demo login

| Field | Value |
|-------|--------|
| Job number | `001` |
| Password | `admin123` |

After pulling updates, refresh permissions and **log in again**:

```bash
php artisan db:seed --class=PermissionSeeder
```

System health: `/dashboard/system-health` (API: `GET /api/users/system/health`).

## Frontend (`HotelManageSystemAngular`)

```bash
cd HotelManageSystemAngular
npm install
ng serve --port=4200
```

Open `http://127.0.0.1:4200` — login uses **job number**, not email.

API URL: `src/environments/environment.ts` (`apiUrl` → port **8001**).

## Recommended demo dates

- **Room board / occupancy:** `2026-08-01`
- **Financial reports:** August `2026-08` (seeded revenue ≈ **3565.04**)
- **Cash box:** June `2026-06` (payments differ from August accrual)

## Verify accounting

```bash
php artisan test --filter=Finance
php artisan demo:verify
```

Both should pass with **0 FAIL**.

## Manual golden path

See `docs/DEMO_TEST_CHECKLIST.md` in the repo root.

Known gaps: `../KNOWN_LIMITATIONS.md`
