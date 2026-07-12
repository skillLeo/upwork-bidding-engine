# SETUP

Copy-paste setup for local development. Two parts: `backend` (Laravel 13 JSON API) and `frontend` (Next.js dashboard).

## 0. Requirements

- **PHP 8.3+** (Laravel 13 requires `^8.3` — check with `php -v` before anything else; an older system PHP is the #1 thing that breaks this setup)
- Composer 2.x
- Node.js 20+ and npm
- MySQL 8+ and Redis, either via Docker or installed natively

## 1. Start MySQL + Redis

**Option A — Docker (recommended):**

```bash
docker compose up -d
```

This starts MySQL on `127.0.0.1:3306` (database `skillleo_engine`, user `root`, empty password) and Redis on `127.0.0.1:6379` — matching `backend/.env.example` exactly, so no edits needed.

**Option B — already have MySQL/Redis running natively:**

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS skillleo_engine CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
redis-server --daemonize yes   # or: brew services start redis
```

If your local MySQL root user has a password, or Redis needs a password, update `backend/.env` accordingly after step 2.

## 2. Backend (Laravel API)

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
```

Seeding creates two users and ~38 demo leads spread across every status:

| Role   | Email                  | Password  |
|--------|-------------------------|-----------|
| Admin  | admin@skillleo.test     | password  |
| Bidder | bidder@skillleo.test    | password  |

Run the app (three processes, separate terminals):

```bash
php artisan serve            # http://127.0.0.1:8000
php artisan queue:work       # processes ScoreLeadJob / NotifyBidderJob / etc.
php artisan horizon          # (optional) queue dashboard at /horizon, replaces queue:work
```

Run tests:

```bash
php artisan test
```

## 3. Frontend (Next.js dashboard)

```bash
cd frontend
npm install
cp .env.local.example .env.local
npm run dev                  # http://127.0.0.1:3000
```

Log in with either seeded user above. Bidders don't see the Settings nav item; hitting it directly (or the API) still 403s server-side.

## 4. Wire up real integrations (optional)

Everything below is configured **in the app** (Settings screen, admin only) — never in `.env`:

1. Log in as `admin@skillleo.test`.
2. Go to **Settings**.
3. Fill in `claude_api_key`, `openclaw_url` + `openclaw_token`, `whatsapp_token` + `whatsapp_phone_id` + `bidder_whatsapp`, and `vollna_webhook_secret`. Use **Test connection** on each section.
4. Point Vollna's webhook at `POST {APP_URL}/api/vollna-hook` with header `X-Vollna-Secret: <vollna_webhook_secret>`.
5. Point your Meta WhatsApp app's webhook at `GET/POST {APP_URL}/api/whatsapp-hook`, using the same `whatsapp_token` value as the verify token during Meta's subscription handshake.

Until these are filled in, the app runs fine end-to-end on seeded data — scoring simply has nothing to call and webhooks return a clear "not configured" test-connection result.

## 5. Scheduler (follow-up reminders)

In production, run Laravel's scheduler via cron (fires `leads:follow-up-reminders` daily, defined in `routes/console.php`):

```
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

Locally, you can trigger it directly instead of waiting on cron:

```bash
php artisan leads:follow-up-reminders
```

## 6. requests.http

`backend/docs/requests.http` has a ready-to-run request for every endpoint (works with the VS Code "REST Client" extension or JetBrains' HTTP Client) — log in, copy the token into the file's `@token` variable, go.
