# SkillLeo Bidding Engine

A system that receives Upwork jobs from Vollna via webhook, scores and drafts a proposal for each one through an external AI service (OpenClaw, powered by Claude), notifies a human bidder on WhatsApp, and tracks every lead/client/conversation/win on one dashboard.

**The one unbreakable rule:** this software only *prepares*. A human sends on Upwork. No part of this app logs into Upwork, scrapes it, or clicks Submit.

## Stack

One Laravel app, no separate frontend service:

- **Backend:** Laravel 13, PHP 8.3+, Sanctum token auth, Horizon for queue monitoring, Predis for Redis.
- **Frontend:** Vue 3 (`<script setup>`), Vue Router, Pinia, Tailwind v4, Chart.js, all built by Vite and served through Blade — a single-page app baked into `resources/js`, `resources/css`, and `resources/views/app.blade.php`.
- **Why one app:** the dashboard is a pure SPA with no server-side rendering, so it needs nothing at runtime beyond static built assets — `npm run build` produces files Apache serves directly. No Node.js process, no separate API domain, no reverse proxy required in production.

## Data flow

| # | From → To | What happens |
|---|-----------|--------------|
| 1 | Vollna → app | Vollna POSTs a new matching Upwork job to `/api/vollna-hook` |
| 2 | app saves | Stored in `leads` with `status = new` |
| 3 | app → OpenClaw | Cheap PHP hard filters run first; if the lead survives, app sends job text + rules and asks OpenClaw to score it + write a proposal |
| 4 | OpenClaw → Claude | OpenClaw calls Claude (key sourced from Settings, passed per-request) to reason + write |
| 5 | OpenClaw → app | Returns `{ score, reason, proposal }` |
| 6 | app saves + decides | Score ≥ cutoff → `status = ready`; else → `archived` |
| 7 | app → WhatsApp | Sends the bidder a card (job + score + proposal) — only for `ready` leads |
| 8 | bidder on Upwork | Pastes the proposal, clicks Submit, taps "Sent" in the dashboard |
| 9 | client replies | Bidder pastes the reply into Client Memory → app asks OpenClaw to draft an answer → bidder sends it |

Cost-saving rule: hard filters (`max_proposals`, budget floors, red-flag keywords) run in plain PHP *before* any paid AI call. A lead that fails them is archived without ever reaching OpenClaw/Claude.

## Modules

### 1. Auth (`AuthController`, Sanctum)
Login issues a bearer token (`Auth::once()` + `createToken()`); logout deletes the current token; `role` (`admin`/`bidder`) is a native PHP enum cast on `User`.

**Test it works when:** `POST /api/auth/login` with a seeded user returns a token, `GET /api/me` with that token returns the user + role, and `GET /api/me` with no token returns a clean `401 {"message":"Unauthenticated."}` (not a redirect — see `bootstrap/app.php`'s `redirectGuestsTo(null)`, required for a Blade-less API).

### 2. Settings (`SettingsService`, `SettingsController`)
Every API key/token and bidding rule lives in the `settings` table, not `.env`. `SettingsService::SCHEMA` is the single source of truth for which keys exist, their group, and whether they're secret. Secrets are `Crypt::encryptString()`'d at rest and cached via `Cache::rememberForever`, invalidated on every write.

**Test it works when:** `POST /api/settings` as admin with `{"claude_api_key":"sk-..."}` then `GET /api/settings` shows `ai.claude_api_key.is_set = true` and a masked value ending in the real key's last 4 characters — while the DB row shows ciphertext, not plaintext. The same request as a bidder token gets `403`.

### 3. Vollna webhook intake (`VollnaWebhookController`, `VerifyVollnaSecret`, `VollnaProjectImporter`)
`POST /api/vollna-hook` — secret verified via `X-Vollna-Secret` header (constant-time compare), rate-limited (`throttle:webhooks`), maps Vollna's real "Notifications → Webhook" payload shape, dedupes on `external_id`, dispatches `ScoreLeadJob`. `VollnaProjectImporter` holds the payload-mapping logic shared between the live webhook and the one-time `vollna:backfill` command (imports jobs Vollna already matched via its REST API, paced to its ~5-requests-per-minute rate limit).

**Test it works when:** the same job POSTed twice returns `201` then `200 {"status":"duplicate"}` with only one row in `leads`; a missing/wrong `X-Vollna-Secret` returns `401` and writes a `webhook_rejected` activity log.

### 4. Scoring & proposal engine (`ScoringService`, `OpenClawService`, `ScoreLeadJob`)
Hard filters (`max_proposals`, budget floors, red-flag words) run in plain PHP first — a lead that fails them is archived with zero HTTP calls. A lead that passes gets POSTed to `{openclaw_url}/task` (Bearer `openclaw_token`, 3 retries with 1s/3s/6s backoff on connection failures, plus job-level retry/backoff on top) along with the Claude key/model from Settings, since OpenClaw is the thing that actually calls Claude on our behalf.

**Test it works when:** `php artisan test --filter=ScoreLeadJobTest` passes — it mocks OpenClaw via `Http::fake()` and asserts hard-filtered leads never call it, high scores flip to `ready` and dispatch `NotifyBidderJob`, low scores archive without notifying.

### 5. Leads API + saved filters + smart search (`LeadController`, `SavedFilterController`, `LeadFilterEvaluator`)
Filter/search/sort/paginate; status transitions are restricted to the forward set (`sent|replied|won|archived` — `new/scoring/ready` are system-controlled); the first transition past `ready` auto-provisions a `Client` row so Client Memory has somewhere to attach messages. `rescore` is admin-only.

Saved filters (name, pin, one default, include/exclude keywords, budget range, payment-verified-only, min client spend, country include/exclude, posted-within-minutes) are CRUD'd account-wide. Searching bypasses the active filter's SQL criteria and instead runs every result through `LeadFilterEvaluator`, which annotates each lead with `matches_filter` + `filter_fail_reasons` — this powers the "Not in filter" badge and the reasons box on the lead detail page.

**Test it works when:** marking a `ready` lead `sent` returns a `client_id` and a matching row now exists in `clients`; a bidder token gets `403` on `/rescore`; a saved filter's `criteria` round-trips through create/update even when empty.

### 6. WhatsApp notifier (`WhatsAppService`, `NotifyBidderJob`, `WhatsAppWebhookController`)
`NotifyBidderJob` sends a formatted card (title, score, reason, budget, bid count, proposal text, dashboard deep link) to `bidder_whatsapp` via the Cloud API. The webhook handles Meta's GET subscription handshake (verify token = `whatsapp_token`) and logs inbound POSTs (messages + delivery statuses) to `activity_logs`.

**Test it works when:** `GET /api/whatsapp-hook?hub.mode=subscribe&hub.verify_token=<whatsapp_token>&hub.challenge=X` echoes back `X`; a wrong token gets `403`.

### 7. Client memory + reply drafting (`ClientController`, `DraftReplyJob`)
Pasting a client message creates an inbound `Message`, then `DraftReplyJob` runs **synchronously** (`dispatchSync`, still a real queueable job) so the "Draft reply" button gets its answer in the same HTTP response. Failures degrade gracefully (the inbound message is still saved, `drafted_reply` stays null) rather than 500ing.

**Test it works when:** `php artisan test --filter=ClientDraftReplyTest` passes, including the case where OpenClaw returns a 500 — the message still saves.

### 8. Analytics (`AnalyticsService`)
Summary stats (reply rate, win rate, avg score, estimated Connects spent) come from the current `leads` table state; time-series data (daily trend, best hour of day) and the recent-activity feed come from `activity_logs`, which is written to on every meaningful action across every module above.

**Test it works when:** `GET /api/analytics` as admin returns non-empty `summary`, `trend`, `best_hours`, and `recent_activity`; as bidder it's `403`.

### 9. Follow-up reminders (`FollowUpReminderCommand`, scheduled in `routes/console.php`)
Daily: any `sent` lead with no reply after `followup_days` gets a WhatsApp nudge. `leads` has no dedicated `sent_at` column, so `updated_at` doubles as the follow-up clock — `touch()`'d after each reminder so a stuck lead gets reminded every `followup_days`, not every single day.

**Test it works when:** `php artisan leads:follow-up-reminders` on a `sent` lead whose `updated_at` is older than the configured window logs a `follow_up_sent` activity entry.

### 10. Dashboard (`resources/js`)
A Vue 3 SPA: Login, Leads Board (three-zone layout — left rail of saved filters, center feed, right rail), Lead Detail, Client Memory, Settings (admin only), Analytics (admin only). LinkedIn-style design — warm off-white background, white cards with hairline borders and soft shadows, `#0A66C2` primary blue, pill-shaped buttons/badges. Auth state lives in a Pinia store persisted to `localStorage`; routing is guarded by a `beforeEach` navigation guard checking the stored token/role.

## Endpoints

See [docs/requests.http](docs/requests.http) for a runnable request against every endpoint. All Sanctum-protected except the two webhooks and `/auth/login`.

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
# set DB_* in .env, then:
php artisan migrate --seed
npm run build      # production assets
# or for local dev, run both in parallel:
php artisan serve
npm run dev
```

## Tests

```bash
php artisan test
```

51 feature tests covering the webhook, the scoring flow (mocked OpenClaw), settings encryption, saved filters, and role authorization.

## Security

- Encrypted secrets at rest, masked in the API and UI, per-service "Test connection".
- Admin-only Settings and Analytics enforced server-side via route middleware (`role:admin`) — never trust the client for this.
- Webhook secret verification (`X-Vollna-Secret`) + rate limiting on both public webhooks.
- Sanctum bearer tokens, revoked on logout.
