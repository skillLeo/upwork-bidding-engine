# SkillLeo Bidding Engine

A system that receives Upwork jobs from Vollna via webhook, scores and drafts a proposal for each one through an external AI service (OpenClaw, powered by Claude), notifies a human bidder on WhatsApp, and tracks every lead/client/conversation/win on one dashboard.

**The one unbreakable rule:** this software only *prepares*. A human sends on Upwork. No part of this app logs into Upwork, scrapes it, or clicks Submit.

See [SETUP.md](SETUP.md) for copy-paste commands to get running.

## Monorepo layout

```
skillleo-engine/
├── backend/           Laravel 13 (PHP 8.3+) — pure JSON REST API
├── frontend/           Next.js 16 (App Router, TypeScript) — the dashboard
├── docker-compose.yml  MySQL + Redis for local dev
├── README.md
└── SETUP.md
```

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

## Backend

- **Framework:** Laravel 13, PHP 8.3+, Sanctum token auth (bidder-facing SPA, not cookie/session auth), Horizon for queue monitoring, Predis for Redis.
- **Architecture:** thin controllers, fat services (`SettingsService`, `ScoringService`, `OpenClawService`, `WhatsAppService`, `AnalyticsService`). Every external call is queued where it matters (`ScoreLeadJob`, `NotifyBidderJob`) with retries + backoff, and logged to `activity_logs`.
- **Settings, not `.env`:** every API key/token and every bidding rule lives in the `settings` table, edited from the Settings screen, admin-only (enforced in middleware, not just hidden in the UI). Secrets are encrypted at rest (`Crypt::encryptString`) and only ever shown masked. `.env` holds infra config only — DB, Redis, app key.
- **Tests:** `php artisan test` — feature tests cover the Vollna webhook (secret verification + idempotency), the scoring pipeline (hard filters + mocked OpenClaw calls), settings encryption, and role authorization.

Full endpoint list, module notes, and a runnable `docs/requests.http` collection live in `backend/`.

## Frontend

- **Stack:** Next.js 16 App Router, TypeScript, Tailwind v4, SWR for data fetching, zustand for auth/session state, react-hook-form + zod for forms, @tanstack/react-table for the leads board, recharts for analytics, sonner for toasts.
- **Design:** a LinkedIn-style shell — warm off-white background, white cards with hairline borders and soft shadows, `#0A66C2` primary blue, pill-shaped buttons/badges, three-zone layout (left rail / center feed / right rail) on the Leads Board.
- **Screens:** Login, Leads Board, Lead Detail, Client Memory, Settings (admin only), Analytics (admin only).

## Security

- Encrypted secrets at rest, masked in the API and UI, per-service "Test connection".
- Admin-only Settings and Analytics enforced server-side via route middleware (`role:admin`) — never trust the client for this.
- Webhook secret verification (`X-Vollna-Secret`) + rate limiting on both public webhooks.
- CORS locked to the configured frontend origin.
- Sanctum bearer tokens, revoked on logout.
