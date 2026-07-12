# SkillLeo Bidding Engine — Backend

Laravel 13 (PHP 8.3+) JSON API. See the [repo root README](../README.md) for the full picture and [../SETUP.md](../SETUP.md) to get running.

## Modules

### 1. Auth (`AuthController`, Sanctum)
Login issues a bearer token (`Auth::once()` + `createToken()`); logout deletes the current token; `role` (`admin`/`bidder`) is a native PHP enum cast on `User`.

**Test it works when:** `POST /api/auth/login` with a seeded user returns a token, `GET /api/me` with that token returns the user + role, and `GET /api/me` with no token returns a clean `401 {"message":"Unauthenticated."}` (not a redirect — see `bootstrap/app.php`'s `redirectGuestsTo(null)`, required for a Blade-less API).

### 2. Settings (`SettingsService`, `SettingsController`)
Every API key/token and bidding rule lives in the `settings` table, not `.env`. `SettingsService::SCHEMA` is the single source of truth for which keys exist, their group, and whether they're secret. Secrets are `Crypt::encryptString()`'d at rest and cached (as a plain array, not Eloquent models — see the comment in `SettingsService::all()`) via `Cache::rememberForever`, invalidated on every write.

**Test it works when:** `POST /api/settings` as admin with `{"claude_api_key":"sk-..."}` then `GET /api/settings` shows `ai.claude_api_key.is_set = true` and a masked value ending in the real key's last 4 characters — while `SELECT value FROM settings WHERE key='claude_api_key'` in the DB shows ciphertext, not plaintext. The same request as a bidder token gets `403`.

### 3. Vollna webhook intake (`VollnaWebhookController`, `VerifyVollnaSecret`)
`POST /api/vollna-hook` — secret verified via `X-Vollna-Secret` header (constant-time compare), rate-limited (`throttle:webhooks`), maps Vollna's payload defensively (a few plausible field-name aliases), dedupes on `external_id`, dispatches `ScoreLeadJob`.

**Test it works when:** the same `id` POSTed twice returns `201` then `200 {"status":"duplicate"}` with only one row in `leads`; a missing/wrong `X-Vollna-Secret` returns `401` and writes a `webhook_rejected` activity log.

### 4. Scoring & proposal engine (`ScoringService`, `OpenClawService`, `ScoreLeadJob`)
Hard filters (`max_proposals`, budget floors, red-flag words) run in plain PHP first — a lead that fails them is archived with zero HTTP calls. A lead that passes gets POSTed to `{openclaw_url}/task` (Bearer `openclaw_token`, 3 retries with 1s/3s/6s backoff on connection failures, plus job-level retry/backoff on top) along with the Claude key/model from Settings, since OpenClaw is the thing that actually calls Claude on our behalf.

**Test it works when:** `php artisan test --filter=ScoreLeadJobTest` passes — it mocks OpenClaw via `Http::fake()` and asserts hard-filtered leads never call it, high scores flip to `ready` and dispatch `NotifyBidderJob`, low scores archive without notifying.

### 5. Leads API (`LeadController`)
Filter/search/sort/paginate; status transitions are restricted to the forward set (`sent|replied|won|archived` — `new/scoring/ready` are system-controlled); the first transition past `ready` auto-provisions a `Client` row so Client Memory has somewhere to attach messages. `rescore` is admin-only.

**Test it works when:** marking a `ready` lead `sent` returns a `client_id` and a matching row now exists in `clients`; a bidder token gets `403` on `/rescore`.

### 6. WhatsApp notifier (`WhatsAppService`, `NotifyBidderJob`, `WhatsAppWebhookController`)
`NotifyBidderJob` sends a formatted card (title, score, reason, budget, bid count, proposal text, dashboard deep link) to `bidder_whatsapp` via the Cloud API. The webhook handles Meta's GET subscription handshake (verify token = `whatsapp_token`, since the settings schema has no separate field for it) and logs inbound POSTs (messages + delivery statuses) to `activity_logs`.

**Test it works when:** `GET /api/whatsapp-hook?hub.mode=subscribe&hub.verify_token=<whatsapp_token>&hub.challenge=X` echoes back `X`; a wrong token gets `403`.

### 7. Client memory + reply drafting (`ClientController`, `DraftReplyJob`)
Pasting a client message creates an inbound `Message`, then `DraftReplyJob` runs **synchronously** (`dispatchSync`, still a real queueable job) so the "Draft reply" button gets its answer in the same HTTP response. `needs_hassam` is set by OpenClaw's response when the message touches price/closing; failures degrade gracefully (the inbound message is still saved, `drafted_reply` stays null) rather than 500ing.

**Test it works when:** `php artisan test --filter=ClientDraftReplyTest` passes, including the case where OpenClaw returns a 500 — the message still saves.

### 8. Analytics (`AnalyticsService`)
Summary stats (reply rate, win rate, avg score, estimated Connects spent) come from the current `leads` table state; time-series data (daily trend, best hour of day) and the recent-activity feed come from `activity_logs`, which is written to on every meaningful action across every module above.

**Test it works when:** `GET /api/analytics` as admin returns non-empty `summary`, `trend`, `best_hours`, and `recent_activity`; as bidder it's `403`.

### 9. Follow-up reminders (`FollowUpReminderCommand`, scheduled in `routes/console.php`)
Daily: any `sent` lead with no reply after `followup_days` gets a WhatsApp nudge. `leads` has no dedicated `sent_at` column (by design, matching the spec's exact schema), so `updated_at` doubles as the follow-up clock — `touch()`'d after each reminder so a stuck lead gets reminded every `followup_days`, not every single day.

**Test it works when:** `php artisan leads:follow-up-reminders` on a `sent` lead whose `updated_at` is older than the configured window logs a `follow_up_sent` activity entry.

## Endpoints

See [docs/requests.http](docs/requests.http) for a runnable request against every endpoint. Full table in the repo root spec; Sanctum-protected except the two webhooks and `/auth/login`.

## Tests

```bash
php artisan test
```

34 feature tests covering the webhook, the scoring flow (mocked OpenClaw), settings encryption, and role authorization.
