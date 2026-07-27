<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Tenant;
use App\Tenancy\Tenancy;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Single source of truth for every runtime-configurable key/value the app
 * uses (API keys, tokens, and bidding rules). Nothing outside this service
 * should read the `settings` table directly, and app logic must never fall
 * back to .env for these — .env only holds infra config (DB/Redis/app key).
 */
class SettingsService
{
    protected const CACHE_KEY = 'settings:all';

    /**
     * Canonical schema: every key the UI can read/write, its group, and
     * whether it must be encrypted at rest + masked in the UI. The server
     * decides secrecy from this map — never trust the client for that.
     *
     * @var array<string, array{group: string, secret: bool, default: mixed}>
     */
    public const SCHEMA = [
        // Branding — product name + logo shown across the app (nav bar,
        // sign-in screen). Not secret: an unauthenticated visitor needs
        // these too, via the public GET /branding route, before signing in.
        'app_name' => ['group' => 'branding', 'secret' => false, 'default' => 'SkillLeo'],
        'app_logo_path' => ['group' => 'branding', 'secret' => false, 'default' => null],

        // Vollna
        'vollna_webhook_secret' => ['group' => 'vollna', 'secret' => true, 'default' => ''],
        // Used only by the manual "Sync now" button (Developers > API
        // Tokens in Vollna, and the numeric filter ID from its dashboard
        // URL) - the live webhook above doesn't need these at all.
        'vollna_api_token' => ['group' => 'vollna', 'secret' => true, 'default' => ''],
        'vollna_filter_id' => ['group' => 'vollna', 'secret' => false, 'default' => ''],
        // Written by VollnaSyncJob, read by the Settings UI to show "last
        // synced: X added, Y removed" under the manual sync button.
        'vollna_last_sync' => ['group' => 'vollna', 'secret' => false, 'default' => null],
        // Dead-man's switch: Vollna has gone silently dark twice (a stale
        // secret, then an API-token outage) with zero signal until someone
        // noticed leads had stopped. The hourly vollna:check-silence
        // command alerts once per incident when no authenticated webhook
        // delivery has arrived within this many hours.
        'vollna_silence_alert_hours' => ['group' => 'vollna', 'secret' => false, 'default' => 6],
        // Stamped by VollnaWebhookController on every authenticated
        // delivery — even an all-duplicates one proves the webhook is
        // alive. Read only by vollna:check-silence.
        'vollna_last_webhook_at' => ['group' => 'vollna', 'secret' => false, 'default' => null],

        // Vollna email intake — Vollna moved webhooks to their Agency
        // plan, but email notifications stay free on Freelancer, so the
        // mailbox becomes the live intake door. Same downstream pipeline:
        // the poller hands parsed jobs to VollnaProjectImporter, exactly
        // like the webhook and the API backfill do.
        'gmail_address' => ['group' => 'vollna', 'secret' => false, 'default' => ''],
        // Gmail rejects the account password over IMAP — this must be a
        // 16-character App Password (Google Account → Security → 2-Step
        // Verification → App passwords).
        'gmail_app_password' => ['group' => 'vollna', 'secret' => true, 'default' => ''],
        'imap_host' => ['group' => 'vollna', 'secret' => false, 'default' => 'imap.gmail.com'],
        'imap_port' => ['group' => 'vollna', 'secret' => false, 'default' => 993],
        'imap_folder' => ['group' => 'vollna', 'secret' => false, 'default' => 'INBOX'],
        // Confirmed against real messages before the parser trusts it.
        'vollna_sender_filter' => ['group' => 'vollna', 'secret' => false, 'default' => 'vollna.com'],
        'imap_poll_enabled' => ['group' => 'vollna', 'secret' => false, 'default' => true],
        // Stamped by ANY successful intake (webhook or email) — the
        // dead-man's switch watches this instead of the webhook-only stamp.
        'vollna_last_intake_at' => ['group' => 'vollna', 'secret' => false, 'default' => null],

        // AI engine — OpenClaw, on its own Claude Code CLI subscription auth.
        // No Anthropic API key/model here: OpenClaw is already authenticated,
        // and the CLI picks its own model.
        'openclaw_url' => ['group' => 'openclaw', 'secret' => true, 'default' => ''],
        'openclaw_token' => ['group' => 'openclaw', 'secret' => true, 'default' => ''],
        // The Agent API's own bearer token — deliberately SEPARATE from
        // everything else: it can read leads/clients and post one status
        // change, never touch settings or keys. Worst case if it leaks:
        // someone reads lead titles.
        'agent_api_token' => ['group' => 'openclaw', 'secret' => true, 'default' => ''],
        'ai_engine_enabled' => ['group' => 'openclaw', 'secret' => false, 'default' => true],
        // A narrower pause than ai_engine_enabled: scoring keeps running
        // (leads still arrive, still get a bid/no-bid verdict, still show
        // on the dashboard) but no proposal is written anywhere — the
        // auto pipeline, the dashboard Rewrite button, and WhatsApp
        // rewrite all read this same flag.
        'proposal_writing_enabled' => ['group' => 'ai', 'secret' => false, 'default' => true],

        // WhatsApp — sent via OpenClaw (already QR-linked to WhatsApp), not
        // Meta's Cloud API, so the only thing this app needs to know is who
        // to message. openclaw_url/openclaw_token above do the connecting.
        'bidder_whatsapp' => ['group' => 'whatsapp', 'secret' => true, 'default' => ''],

        // Global control over automated WhatsApp sends — a dashboard toggle
        // rather than a WhatsApp text command, since OpenClaw has no way to
        // forward inbound replies to this app (confirmed against its own
        // docs: it only supports Gmail Pub/Sub webhooks, not a generic
        // incoming-message URL hook). 'paused' stops reminders/follow-ups
        // only, fresh lead cards still arrive; 'muted' stops everything.
        'whatsapp_alert_mode' => ['group' => 'whatsapp', 'secret' => false, 'default' => 'normal'],

        // Meta's official WhatsApp Business Cloud API. Sanctioned
        // server-to-server HTTP, so it needs no Mac, no ngrok tunnel and no
        // QR-linked companion session — which is exactly what the OpenClaw
        // path depends on and why that path keeps dying. When
        // whatsapp_cloud_enabled is on AND the credentials are present, the
        // Cloud API is used for outbound alerts and OpenClaw is bypassed.
        // Unlike OpenClaw it can also RECEIVE messages, which is what makes
        // BID/SKIP/PAUSE replies possible at all.
        'whatsapp_cloud_enabled' => ['group' => 'whatsapp', 'secret' => false, 'default' => false],
        'whatsapp_phone_number_id' => ['group' => 'whatsapp', 'secret' => false, 'default' => ''],
        'whatsapp_waba_id' => ['group' => 'whatsapp', 'secret' => false, 'default' => ''],
        'whatsapp_access_token' => ['group' => 'whatsapp', 'secret' => true, 'default' => ''],
        // Verifies X-Hub-Signature-256 on inbound webhooks. Without it anyone
        // who learns the URL could POST forged "BID"/"PAUSE" commands.
        'whatsapp_app_secret' => ['group' => 'whatsapp', 'secret' => true, 'default' => ''],
        // Echoed back during Meta's GET subscription handshake.
        'whatsapp_verify_token' => ['group' => 'whatsapp', 'secret' => true, 'default' => ''],
        // The approved UTILITY template used to open a conversation outside
        // the 24-hour service window. Utility, never Marketing — a lead alert
        // is transactional, and Marketing is ~5x the price per message.
        'whatsapp_template_name' => ['group' => 'whatsapp', 'secret' => false, 'default' => 'fresh_lead'],
        'whatsapp_template_language' => ['group' => 'whatsapp', 'secret' => false, 'default' => 'en'],
        // Last time the operator messaged us. Meta allows cheaper, free-form
        // replies for 24h after this; outside it we must open with a template.
        'whatsapp_last_inbound_at' => ['group' => 'whatsapp', 'secret' => false, 'default' => null],

        // Direct AI layer — scoring/proposals via the Anthropic (or OpenAI)
        // API from Laravel itself, no OpenClaw hop. The system prompts are
        // deliberately settings, not code: the operator pastes and tunes
        // their own rules in the browser, no redeploy. Model IDs verified
        // against the current model catalog — don't add guessed IDs here.
        'ai_provider' => ['group' => 'ai', 'secret' => false, 'default' => 'anthropic'],
        'anthropic_api_key' => ['group' => 'ai', 'secret' => true, 'default' => ''],
        'openai_api_key' => ['group' => 'ai', 'secret' => true, 'default' => ''],
        'scoring_model' => ['group' => 'ai', 'secret' => false, 'default' => 'claude-haiku-4-5'],
        'proposal_model' => ['group' => 'ai', 'secret' => false, 'default' => 'claude-sonnet-5'],
        // The review pass gets its own model: the weakest writer grading
        // its own homework is how the tricolon shipped. Defaults to the
        // strongest writing-tier model, independent of the writer.
        'review_model' => ['group' => 'ai', 'secret' => false, 'default' => 'claude-sonnet-5'],
        // Neither provider exposes real-time remaining balance through a
        // normal API key (confirmed live: OpenAI's usage API needs a
        // separate Admin key with a scope regular keys don't have;
        // Anthropic has no such endpoint at all on a messages key). So
        // "remaining" is computed honestly instead: what you say you
        // funded, minus what the ai_calls ledger proves you actually
        // spent. Update these whenever you top up.
        'anthropic_funded_total' => ['group' => 'ai', 'secret' => false, 'default' => 0],
        'openai_funded_total' => ['group' => 'ai', 'secret' => false, 'default' => 0],
        'scoring_system_prompt' => ['group' => 'ai', 'secret' => false, 'default' => ''],
        // Account stage: the rubric's own Recommendations section defines a
        // two-phase plan (new account with tight floors and no boosting,
        // then established with raised floors). This makes that switch a
        // setting instead of a memory. stage_1_new = today's rubric behavior
        // with boost force-disabled; stage_2_established appends the
        // (editable) addendum below to the scoring prompt.
        'account_stage' => ['group' => 'ai', 'secret' => false, 'default' => 'stage_1_new'],
        'stage_2_scoring_addendum' => ['group' => 'ai', 'secret' => false, 'default' => 'Account update: SkillLeo now has reviews and a Job Success Score. Adjust: budget floors rise to fixed >= $400 and hourly >= $20 (operator-editable). Hourly contracts are now acceptable; remove the fixed-price-small-job bonus. Boost may be recommended on scores 9-10 worth >= $1,000. Client spend of $10k+ is now a positive signal, not out of reach. Everything else in the rubric is unchanged.'],
        // The proposal rules are deliberately SPLIT: models imitate the
        // style of their context far more than they obey instructions in
        // it, so the dense teaching document must never enter a prompt.
        // proposal_skill (SKILL.md v2, ~9KB) + project_facts are the ONLY
        // operator text any proposal call ever sees; proposal_reference
        // is the teaching addendum + guide, stored for the operator to
        // read in the browser and nothing else.
        'proposal_skill' => ['group' => 'ai', 'secret' => false, 'default' => ''],
        'proposal_reference' => ['group' => 'ai', 'secret' => false, 'default' => ''],
        // The system message written into every exported training example
        // (proposals:export-training-data). Left empty, the export falls back
        // to proposal_skill - the actual style guide the writer was given - so
        // brief->final pairs teach the same voice. Set this only to train
        // against a different system prompt than the live one.
        'training_system_prompt' => ['group' => 'ai', 'secret' => false, 'default' => ''],
        // Canonical fact sheet — the only source of truth about Hassam's
        // track record. Anything not derivable from this must never be
        // claimed in a proposal.
        // Empty by default, and that is the point. This used to ship the
        // founding user's entire track record as the hardcoded default, so
        // EVERY workspace was born able to claim his projects as its own —
        // the worst possible leak, since it ends up inside a proposal sent to
        // a stranger. A workspace states its own record or states nothing;
        // WorkspaceReadiness stops proposals until it does.
        'project_facts' => ['group' => 'ai', 'secret' => false, 'default' => ''],

        // Proposal quality gate — every generated proposal is mechanically
        // linted (banned phrases, word count, signature, required phrases)
        // AND re-read by the model against the full rules, then revised
        // until it complies. The gate's data lives here, not in code, so
        // the operator can tune it in the browser like the prompts above.
        // Defaults seeded from SKILL.md v2 in docs/ai-rules.
        'proposal_quality_gate' => ['group' => 'ai', 'secret' => false, 'default' => true],
        // v3 target range (down from 110-180): 2026 evidence shows the
        // 100-149 word band underperforms on reply rate, and very short,
        // sharp letters do better on small single-task jobs. Live values
        // may have been hand-tuned in Settings and this default only
        // applies to a fresh install — check the UI if you want the
        // running value to match.
        'proposal_min_words' => ['group' => 'ai', 'secret' => false, 'default' => 90],
        'proposal_max_words' => ['group' => 'ai', 'secret' => false, 'default' => 150],
        'proposal_signature' => ['group' => 'ai', 'secret' => false, 'default' => ''],
        'proposal_required_phrases' => ['group' => 'ai', 'secret' => false, 'default' => ['Done =']],
        'proposal_banned_phrases' => ['group' => 'ai', 'secret' => false, 'default' => [
            // Em/en dashes and the double hyphen — the #1 AI tell the
            // operator flagged.
            '—', '–', '--',
            // AI-tell vocabulary (SKILL.md v2).
            'delve', 'leverage', 'seamless', 'seamlessly', 'robust', 'elevate',
            'meticulous', 'meticulously', 'tapestry', 'testament', 'realm',
            'landscape', 'unlock', 'unleash', 'harness', 'navigate', 'navigating',
            'dive into', 'deep dive', 'game-changer', 'cutting-edge',
            'state-of-the-art', 'synergy', 'synergize', 'streamline', 'empower',
            'foster', 'bolster', 'in today\'s fast-paced world', 'ever-evolving',
            'holistic', 'efficiently', 'efficient', 'flawless', 'flawlessly',
            // Banned sentence starts.
            'Moreover,', 'Furthermore,', 'Additionally,', 'In conclusion,',
            // Banned clichés / openers.
            'Dear Sir', 'To whom it may concern', 'I hope this message finds you well',
            'I hope you are doing well', 'I am writing to apply',
            'I came across your job posting', 'I am the best fit',
            'best fit for this role', 'Kindly', 'I am excited to', 'I am thrilled',
            'Greetings', 'Hello there', 'As an experienced professional',
            'Please let me know if you\'re interested',
            'Looking forward to your positive response', 'I am not a bot',
            'I guarantee', 'you won\'t find better', '100% satisfaction',
        ]],

        // Mail — SMTP creds configured here instead of .env so they can be
        // changed without a redeploy. Applied at runtime in
        // AppServiceProvider::boot(); .env's MAIL_MAILER=log stays as the
        // inert fallback until these are actually filled in.
        'mail_host' => ['group' => 'mail', 'secret' => false, 'default' => ''],
        'mail_port' => ['group' => 'mail', 'secret' => false, 'default' => 587],
        'mail_username' => ['group' => 'mail', 'secret' => true, 'default' => ''],
        'mail_password' => ['group' => 'mail', 'secret' => true, 'default' => ''],
        'mail_encryption' => ['group' => 'mail', 'secret' => false, 'default' => 'tls'],
        'mail_from_address' => ['group' => 'mail', 'secret' => false, 'default' => ''],
        'mail_from_name' => ['group' => 'mail', 'secret' => false, 'default' => 'SkillLeo'],

        // Web Push (VAPID) identity - auto-generated once via
        // `php artisan push:generate-vapid-keys`, then reused. The public key
        // is handed to the browser to subscribe; the private key signs pushes.
        'vapid_public_key' => ['group' => 'push', 'secret' => false, 'default' => ''],
        'vapid_private_key' => ['group' => 'push', 'secret' => true, 'default' => ''],

        // Rules
        'min_budget' => ['group' => 'rules', 'secret' => false, 'default' => 150],
        'max_proposals' => ['group' => 'rules', 'secret' => false, 'default' => 25],
        'score_cutoff' => ['group' => 'rules', 'secret' => false, 'default' => 7],
        // DEPRECATED flat list - superseded by core/secondary/excluded_stacks
        // below, which the scorer, writer, and linter all read. Kept only so
        // old persisted rows don't error; nothing reads it anymore.
        // DEPRECATED flat list - superseded by core/secondary/excluded_stacks
        // below, which the scorer, writer, and linter all read. Kept only so
        // old persisted rows don't error; nothing reads it anymore.
        'stack_keywords' => ['group' => 'rules', 'secret' => false, 'default' => []],
        // The single source of truth for what stacks are in scope, split by
        // strength. The scoring rubric, the proposal writer, and the tech
        // linter are all rendered from these three lists at prompt-build time -
        // no stack name is hardcoded in any prompt text or in linter code.
        // Core = lead pitch, claim freely, score high. Secondary = can do,
        // score medium, may mention but never claim as a named project's core
        // unless project_facts backs it. Excluded = out of scope, score low,
        // never claim (the linter's deny-list).
        //
        // ALL THREE DEFAULT TO EMPTY, deliberately. They used to default to
        // the founding user's own stacks, which meant a brand-new workspace
        // silently scored every lead against HIS definition of in-scope work
        // and rejected jobs in its own speciality. A workspace's stacks are
        // the one thing nobody else can guess for it. A workspace with no
        // core stacks does not score at all (see WorkspaceReadiness) rather
        // than scoring against somebody else's.
        'core_stacks' => ['group' => 'rules', 'secret' => false, 'default' => []],
        'secondary_stacks' => ['group' => 'rules', 'secret' => false, 'default' => []],
        'excluded_stacks' => ['group' => 'rules', 'secret' => false, 'default' => []],
        // Priority-sort decay: the leads list's default "Priority" order is
        // score minus (hours-since-posted * this rate), so a high score fades
        // in position as the lead ages and a fresh mid-score can outrank a
        // stale high-score. At 0.05, a 9 loses ~1 point every 20h; a 9 from 2
        // days back (score 6.6) drops below a 7 posted minutes ago. Tune here.
        'priority_decay_rate' => ['group' => 'rules', 'secret' => false, 'default' => 0.05],
        // External dead-man's-switch ping (Healthchecks.io / Cronitor / UptimeRobot free
        // tier). The scheduler itself can't alert about its own death - everything that
        // WOULD alert (the queue drain, health checks, vollna:check-silence) rides the same
        // single Hostinger cron entry, so if that cron dies, the alarm dies with it. This
        // URL is pinged from a channel outside this server on every successful tick, so an
        // external monitor - not this app - notices the silence. Empty = disabled, no ping
        // sent, nothing to configure by default.
        'heartbeat_ping_url' => ['group' => 'rules', 'secret' => false, 'default' => ''],
        // Controls public self-serve signup: open (anyone), invite_code
        // (needs a code — the launch default so growth is deliberate), or
        // closed (invite-only). Platform-level: one switch for the whole
        // deployment, never per tenant.
        'signup_mode' => ['group' => 'rules', 'secret' => false, 'default' => 'invite_code'],
        'hourly_floor' => ['group' => 'rules', 'secret' => false, 'default' => 8],
        'zero_history_budget_floor' => ['group' => 'rules', 'secret' => false, 'default' => 100],
        'red_flag_words' => ['group' => 'rules', 'secret' => false, 'default' => ['free test', 'unpaid sample', 'urgent no budget', 'revenue share only']],
        // Every workspace is served the SAME lead feed — one Vollna
        // subscription, fanned out — so a designer's board receives the
        // backend jobs too. Scoring is the only part of that which costs
        // money, and it costs it once PER WORKSPACE. This spends it only on
        // jobs that mention something the workspace actually works in.
        //
        // Nothing is hidden or archived by this: an unmatched lead sits on
        // the board as normal, unscored, with a reason on the row, and can
        // be scored by hand at any time. Turn it off to have every lead
        // scored regardless of stack.
        'stack_gate_enabled' => ['group' => 'rules', 'secret' => false, 'default' => true],
        'followup_days' => ['group' => 'rules', 'secret' => false, 'default' => 3],
        // Separate from the rubric's 7-day auto-reject: a 3-day-old 8/10
        // still gets scored and written (visible on the dashboard), but a
        // lead older than this at scoring time doesn't ring the phone —
        // fresh leads are the whole bidding strategy. 0 disables the gate.
        'notification_freshness_hours' => ['group' => 'rules', 'secret' => false, 'default' => 48],
        // The bar for actually ringing the phone. Deliberately separate from
        // score_cutoff (which decides whether a lead is worth WRITING a
        // proposal for, default 7): a 7/10 is worth having on the dashboard,
        // an 8+ is worth interrupting the operator for. Read by
        // NotificationDispatcher and by the reminder sweep, so the alert bar
        // and the reminder bar can never drift apart.
        'notify_score_min' => ['group' => 'rules', 'secret' => false, 'default' => 8],
        // Reminder quiet window, in Pakistan time (Asia/Karachi) regardless of
        // the app's UTC storage timezone. Start > end wraps midnight, which is
        // the normal case (23 -> 7). Set both to the same hour to disable.
        // A reminder buzzing the operator's phone at 2am is worse than no
        // reminder at all; brand new lead alerts are NOT gated by this,
        // because a fresh lead at 2am is still worth knowing about.
        'quiet_hours_start' => ['group' => 'rules', 'secret' => false, 'default' => 23],
        'quiet_hours_end' => ['group' => 'rules', 'secret' => false, 'default' => 7],
        // A stale posting is a spend-saving pre-check, not a visibility
        // rule — a lead that fails this still saves, still shows up in the
        // dashboard as Archived, and is never deleted. It only skips the
        // paid AI call for a job that's realistically already gone.
        'max_posted_age_days' => ['group' => 'rules', 'secret' => false, 'default' => 7],

        // AI spend quota — per tenant, enforced in AiManager BEFORE any
        // provider call (so a refused call never reaches the ledger at all).
        // 0 = no cap. Usage is computed live from the ai_calls ledger for the
        // current calendar month, never stored as a running counter, so it
        // can never drift from the true spend.
        'ai_monthly_token_cap' => ['group' => 'quotas', 'secret' => false, 'default' => 0],
        // Off: the 100% line still shows the "paused" banner reason in the
        // ledger, but calls keep flowing — a soft warning only. On: AiManager
        // throws before the call, and Diagnostics/the dashboard surface why.
        'ai_hard_stop_on_cap' => ['group' => 'quotas', 'secret' => false, 'default' => false],

        // Workspace-wide 2FA requirement (P6). Owner/admin editable. A member
        // with neither TOTP nor email-OTP enrolled is redirected to enrolment
        // on next login and can reach nothing else until they enrol — except
        // the tenant's LAST remaining owner, who is never locked out by a
        // setting they themselves may be the only one able to undo.
        'require_2fa' => ['group' => 'security', 'secret' => false, 'default' => false],

        // Platform-only (see config/tenancy.php platform_only_keys): editable
        // display metadata for each plan tier. Not billing — no price
        // enforcement reads this, Stripe is explicitly out of scope for P5.
        // Shape: [{key, label, lead_cap, notes}, ...].
        'platform_plan_definitions' => ['group' => 'platform', 'secret' => false, 'default' => []],

        // Platform-only "global AI model defaults" (P5 platform console).
        // Deliberately SEPARATE keys from the per-tenant scoring_model /
        // proposal_model / review_model above, not a platform-layer write of
        // those same keys — conflating the two would mean editing this from
        // the platform console silently changed the platform-owning
        // workspace's OWN live model choice. Not yet consulted as a runtime
        // fallback by SettingsService::all() (that 3-layer resolution is a
        // hot path every request touches); today this is admin-facing
        // guidance for what new workspaces should be seeded with.
        'platform_default_scoring_model' => ['group' => 'platform', 'secret' => false, 'default' => 'claude-haiku-4-5'],
        'platform_default_proposal_model' => ['group' => 'platform', 'secret' => false, 'default' => 'claude-sonnet-5'],
        'platform_default_review_model' => ['group' => 'platform', 'secret' => false, 'default' => 'claude-sonnet-5'],

        // Google OAuth (P6) — one app registration for the whole product
        // (its authorized redirect URIs are tied to this deployment's
        // domain), not a per-tenant credential, so platform-only like mail.
        // Configured dynamically here rather than .env for the same reason
        // mail is: changeable without a redeploy. See AppServiceProvider's
        // configureDynamicGoogleOAuth().
        'google_oauth_client_id' => ['group' => 'oauth', 'secret' => false, 'default' => ''],
        'google_oauth_client_secret' => ['group' => 'oauth', 'secret' => true, 'default' => ''],
    ];

    public const SERVICES = ['vollna', 'openclaw', 'whatsapp', 'mail', 'anthropic', 'openai', 'heartbeat'];

    /**
     * All settings, decrypted, keyed by setting key.
     *
     * THREE-LAYER RESOLUTION, cheapest last:
     *   1. this tenant's own row      (settings.tenant_id = current)
     *   2. the platform default row   (settings.tenant_id IS NULL)
     *   3. the hardcoded SCHEMA default
     *
     * THE CACHE KEY IS PER TENANT, and this is not a detail. The previous
     * implementation was Cache::rememberForever('settings:all'), one global
     * key holding DECRYPTED values including the Anthropic and Vollna
     * credentials. With two tenants that returns whichever tenant warmed the
     * cache first — tenant B reading tenant A's API keys. Never
     * rememberForever tenant-scoped data, and never share a key across
     * tenants.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $tenantId = app(TenantContext::class)->id();

        // A bounded TTL as well as per-tenant keying: forever + a missed
        // invalidation somewhere is indefinitely stale credentials, and an
        // hour is a cheap ceiling on how wrong this can get.
        $rows = Cache::remember($this->cacheKey($tenantId), 3600, function () {
            // Cache a plain array, never Eloquent models/collections — caching
            // ORM objects through serialization is fragile (lazy-loading
            // state, connection resolvers) and unnecessary for scalars.
            //
            // The model's own scope already limits this to
            // "this tenant OR platform default"; ordering puts the tenant's
            // own row last so it overwrites the platform default in keyBy().
            return Setting::query()
                ->orderByRaw('tenant_id is null desc')
                ->get(['key', 'value', 'is_secret', 'tenant_id'])
                ->keyBy('key')
                ->map(fn (Setting $setting) => [
                    'value' => $setting->value,
                    'is_secret' => (bool) $setting->is_secret,
                ])
                ->all();
        });

        $resolved = [];

        foreach (self::SCHEMA as $key => $meta) {
            $row = $rows[$key] ?? null;
            $resolved[$key] = $row ? $this->decode($row['value'], $row['is_secret']) : $meta['default'];
        }

        return $resolved;
    }

    /**
     * Platform defaults live under their own key so they are not duplicated
     * into, or invalidated by, every tenant's cache entry.
     */
    protected function cacheKey(?int $tenantId): string
    {
        return $tenantId === null
            ? self::CACHE_KEY.':platform'
            : self::CACHE_KEY.':tenant:'.$tenantId;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default ?? self::SCHEMA[$key]['default'] ?? null;
    }

    /**
     * Read a PLATFORM-only key from the platform layer, whatever tenant
     * happens to be bound (P8).
     *
     * The pooled AI credentials must resolve to the same value for every
     * workspace. get() would already do that today — after the custody
     * migration no tenant row exists for these keys, and writing one throws —
     * but "correct because no counter-example currently exists in the
     * database" is not the same as "cannot be otherwise". This ignores the
     * tenant layer outright, so a stray row (a bad restore, a hand-edited
     * table, a future bug) can never let one workspace bill its calls to a
     * different key than its neighbour.
     *
     * Refuses non-platform keys rather than quietly returning a tenant value
     * from a platform-shaped call.
     */
    public function platform(string $key, mixed $default = null): mixed
    {
        if (! array_key_exists($key, self::SCHEMA)) {
            throw new \InvalidArgumentException("Unknown setting key [{$key}].");
        }

        if (! $this->isPlatformOnly($key)) {
            throw new \InvalidArgumentException(
                "[{$key}] is not a platform-level setting — read it with get() so the tenant's own value is honoured."
            );
        }

        // Its OWN cache key, not cacheKey(null). That key is written by
        // all() as well, and all() run in platform context caches every row
        // it can see — tenant rows included, overwriting the platform ones.
        // Sharing the key would let one unscoped read anywhere in the app
        // poison this one with a single tenant's values.
        //
        // TENANCY: the platform layer is tenant_id IS NULL by definition, and
        // the Setting model's scope would otherwise add the bound tenant's
        // rows back in. This is the platform default, read as platform.
        $rows = Cache::remember(self::CACHE_KEY.':platform-layer', 3600, fn () => Tenancy::asPlatform(
            fn () => Setting::query()
                ->whereNull('tenant_id')
                ->get(['key', 'value', 'is_secret'])
                ->keyBy('key')
                ->map(fn (Setting $setting) => [
                    'value' => $setting->value,
                    'is_secret' => (bool) $setting->is_secret,
                ])
                ->all()
        ));

        $row = $rows[$key] ?? null;
        $value = $row ? $this->decode($row['value'], $row['is_secret']) : null;

        return $value ?? $default ?? self::SCHEMA[$key]['default'];
    }

    /**
     * @return array<string, mixed>
     */
    public function forGroup(string $group): array
    {
        $all = $this->all();

        return collect(self::SCHEMA)
            ->filter(fn (array $meta) => $meta['group'] === $group)
            ->keys()
            ->mapWithKeys(fn (string $key) => [$key => $all[$key]])
            ->all();
    }

    /**
     * Persist one setting, encrypting it if the schema marks it secret.
     */
    public function set(string $key, mixed $value): void
    {
        if (! array_key_exists($key, self::SCHEMA)) {
            throw new \InvalidArgumentException("Unknown setting key [{$key}].");
        }

        $meta = self::SCHEMA[$key];

        $this->writeRow($key, $this->encode($value, $meta['secret']), $meta);

        $this->isPlatformOnly($key) ? $this->forgetAllCaches() : $this->forgetCache();
    }

    /**
     * Persist many settings at once (Settings page "Save" does one round trip).
     *
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        $touchedPlatform = false;

        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::SCHEMA)) {
                continue;
            }

            $meta = self::SCHEMA[$key];
            $touchedPlatform = $touchedPlatform || $this->isPlatformOnly($key);

            $this->writeRow($key, $this->encode($value, $meta['secret']), $meta);
        }

        // A platform default changed, so every tenant that inherits it is
        // now serving a stale value — not just this one.
        $touchedPlatform ? $this->forgetAllCaches() : $this->forgetCache();
    }

    /**
     * Invalidates ONLY the current tenant's entry — never a blanket flush.
     * One tenant saving a setting must not force every other tenant to
     * re-read and re-decrypt its own credentials.
     */
    public function forgetCache(): void
    {
        Cache::forget($this->cacheKey(app(TenantContext::class)->id()));
    }

    /**
     * Invalidates the platform-default entry AND every tenant's, for the
     * rare case where a platform default changed and tenants without an
     * override are now serving a stale value.
     */
    public function forgetAllCaches(): void
    {
        Cache::forget($this->cacheKey(null));
        Cache::forget(self::CACHE_KEY.':platform-layer');

        // TENANCY: deliberately cross-tenant — a platform default changed, so
        // every tenant that inherits it must re-read.
        Tenancy::asPlatform(function () {
            foreach (Tenant::query()->pluck('id') as $id) {
                Cache::forget($this->cacheKey((int) $id));
            }
        });
    }

    /**
     * Which layer a write lands on.
     *
     * Ordinary keys go to the current tenant. The scoring rubric, the
     * drafting skill and the mail credentials are the product and the
     * infrastructure rather than any customer's configuration, so they may
     * only ever exist as ONE platform row.
     *
     * The spec said "attempting to write a tenant override for these
     * throws". Taken literally that breaks the live Settings page, which has
     * an AI Models and Prompts tab that saves scoring_system_prompt — and
     * this phase is explicitly not allowed to change the UI. So instead of
     * refusing the write, a platform-only key written from the
     * PLATFORM-OWNING workspace (plan 'internal') is redirected to the
     * platform layer. The invariant the rule actually protects — that no
     * per-tenant override row exists for these keys — holds exactly, and
     * nothing in the product forks per customer.
     *
     * A real customer's workspace still gets the throw.
     */

    /**
     * Write platform-only keys to the PLATFORM layer explicitly (P8).
     *
     * setMany() infers the layer from the bound tenant, and for a
     * platform-only key that inference only lands on the platform row when
     * the request happens to be running as the internal workspace. The
     * platform console must not depend on which subdomain the platform owner
     * reached it through, so it says where the write goes instead of hoping.
     *
     * Refuses anything that is not platform-only — this is not a back door
     * for writing a tenant's settings without a tenant.
     *
     * @param  array<string, mixed>  $values
     */
    public function setManyOnPlatformLayer(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::SCHEMA)) {
                continue;
            }

            if (! $this->isPlatformOnly($key)) {
                throw new \InvalidArgumentException(
                    "[{$key}] is a tenant setting — write it with set()/setMany() so it lands on the workspace that owns it."
                );
            }

            $meta = self::SCHEMA[$key];

            // TENANCY: a platform default is tenant_id NULL by definition.
            Tenancy::asPlatform(
                fn () => $this->writeRow($key, $this->encode($value, $meta['secret']), $meta, forceTenantId: null, forced: true)
            );
        }

        $this->forgetAllCaches();
    }

    /**
     * Upsert one settings row on the correct layer.
     *
     * Hand-rolled rather than updateOrCreate() for one specific reason:
     * tenant_id is deliberately NOT mass-assignable on any model (a request
     * must never be able to post its own tenant_id), so updateOrCreate would
     * silently DROP it from the create path — writing the row to the wrong
     * layer, or to no layer at all. setAttribute bypasses fillable, which is
     * safe here because the value comes from writeTenantId(), never a caller.
     *
     * @param  array{group: string, secret: bool, default: mixed}  $meta
     */
    protected function writeRow(string $key, string $encoded, array $meta, ?int $forceTenantId = null, bool $forced = false): void
    {
        $tenantId = $forced ? $forceTenantId : $this->writeTenantId($key);

        $row = Setting::query()
            ->where('key', $key)
            ->when($tenantId === null,
                fn ($q) => $q->whereNull('tenant_id'),
                fn ($q) => $q->where('tenant_id', $tenantId),
            )
            ->first();

        if ($row === null) {
            $row = new Setting;
            $row->setAttribute('tenant_id', $tenantId);
            $row->key = $key;
        }

        $row->value = $encoded;
        $row->group = $meta['group'];
        $row->is_secret = $meta['secret'];
        $row->save();
    }

    protected function writeTenantId(string $key): ?int
    {
        $context = app(TenantContext::class);
        $tenantId = $context->id();

        if (! in_array($key, (array) config('tenancy.platform_only_keys', []), true)) {
            return $tenantId;
        }

        if ($tenantId === null) {
            return null; // already writing the platform default
        }

        if ($context->get()?->plan === 'internal') {
            return null; // platform owner editing the product's own defaults
        }

        throw new \InvalidArgumentException(
            "[{$key}] is a platform-level setting and cannot be overridden per tenant."
        );
    }

    protected function isPlatformOnly(string $key): bool
    {
        return in_array($key, (array) config('tenancy.platform_only_keys', []), true);
    }

    protected function encode(mixed $value, bool $secret): string
    {
        $json = json_encode($value);

        return $secret ? Crypt::encryptString($json) : $json;
    }

    protected function decode(?string $stored, bool $secret): mixed
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        try {
            $json = $secret ? Crypt::decryptString($stored) : $stored;
        } catch (\Throwable) {
            // Key rotated or value corrupted — fail closed, never leak ciphertext.
            return null;
        }

        return json_decode($json, true);
    }

    // ---- Typed convenience accessors used by services/jobs ----

    /**
     * @return array{host: string, port: int, username: string, password: string, encryption: string, from_address: string, from_name: string}
     */
    public function mailConfig(): array
    {
        return [
            'host' => (string) $this->get('mail_host'),
            'port' => (int) $this->get('mail_port'),
            'username' => (string) $this->get('mail_username'),
            'password' => (string) $this->get('mail_password'),
            'encryption' => (string) $this->get('mail_encryption'),
            'from_address' => (string) $this->get('mail_from_address'),
            'from_name' => (string) $this->get('mail_from_name'),
        ];
    }

    public function vollnaWebhookSecret(): ?string
    {
        return $this->get('vollna_webhook_secret') ?: null;
    }

    public function vollnaApiToken(): ?string
    {
        return $this->get('vollna_api_token') ?: null;
    }

    public function vollnaFilterId(): ?string
    {
        return $this->get('vollna_filter_id') ?: null;
    }

    /**
     * @return array{host: string, port: int, folder: string, address: string, password: string, sender: string, enabled: bool}
     */
    public function imapConfig(): array
    {
        return [
            'host' => (string) $this->get('imap_host'),
            'port' => (int) $this->get('imap_port'),
            'folder' => (string) $this->get('imap_folder'),
            'address' => (string) $this->get('gmail_address'),
            // Google displays App Passwords as "abcd efgh ijkl mnop"; the
            // spaces are presentation only and Gmail rejects them on some
            // IMAP paths, so strip whitespace rather than make the
            // operator guess why auth failed.
            'password' => preg_replace('/\s+/', '', (string) $this->get('gmail_app_password')) ?? '',
            'sender' => (string) $this->get('vollna_sender_filter'),
            'enabled' => (bool) $this->get('imap_poll_enabled', true),
        ];
    }

    public function openClawUrl(): ?string
    {
        return $this->get('openclaw_url') ?: null;
    }

    public function openClawToken(): ?string
    {
        return $this->get('openclaw_token') ?: null;
    }

    public function agentApiToken(): ?string
    {
        return $this->get('agent_api_token') ?: null;
    }

    /**
     * Kill switch for AI calls — lets Vollna keep filling `leads` while
     * scoring/drafting is paused, instead of an all-or-nothing outage.
     */
    public function aiEngineEnabled(): bool
    {
        return (bool) $this->get('ai_engine_enabled', true);
    }

    public function bidderWhatsapp(): ?string
    {
        return $this->get('bidder_whatsapp') ?: null;
    }

    /**
     * @return 'normal'|'paused'|'muted'
     */
    public function whatsappAlertMode(): string
    {
        $mode = (string) $this->get('whatsapp_alert_mode', 'normal');

        return in_array($mode, ['normal', 'paused', 'muted'], true) ? $mode : 'normal';
    }

    public function appName(): string
    {
        return (string) ($this->get('app_name') ?: 'SkillLeo');
    }

    public function appLogoUrl(): ?string
    {
        $path = $this->get('app_logo_path');

        return $path ? Storage::disk('public')->url($path) : null;
    }

    /**
     * The proposal quality gate's mechanical checklist — like the prompts,
     * this is operator data, not code.
     *
     * @return array{enabled: bool, min_words: int, max_words: int, signature: string, required_phrases: array<int, string>, banned_phrases: array<int, string>}
     */
    public function proposalGate(): array
    {
        return [
            'enabled' => (bool) $this->get('proposal_quality_gate', true),
            'min_words' => (int) $this->get('proposal_min_words'),
            'max_words' => (int) $this->get('proposal_max_words'),
            'signature' => trim((string) $this->get('proposal_signature')),
            'required_phrases' => array_values(array_filter(array_map('trim', (array) $this->get('proposal_required_phrases')))),
            'banned_phrases' => array_values(array_filter(array_map('trim', (array) $this->get('proposal_banned_phrases')))),
        ];
    }

    /**
     * The three stack lists, cleaned (trimmed, blanks dropped). The single
     * source of truth for stack scope, read by scoring, proposal writing, and
     * the tech linter.
     *
     * @return array{core: array<int, string>, secondary: array<int, string>, excluded: array<int, string>}
     */
    public function stackLists(): array
    {
        $clean = fn (mixed $v): array => array_values(array_filter(array_map('trim', (array) $v), fn ($s) => $s !== ''));

        return [
            'core' => $clean($this->get('core_stacks')),
            'secondary' => $clean($this->get('secondary_stacks')),
            'excluded' => $clean($this->get('excluded_stacks')),
        ];
    }

    /**
     * The stack lists rendered as a static prompt block, injected verbatim
     * into both the scoring rubric and the proposal writer so the two can
     * never disagree about what is in scope. Stays byte-identical across calls
     * (until the operator edits a list), so it lives inside the cached prefix.
     */
    public function stackContext(): string
    {
        $lists = $this->stackLists();
        $fmt = fn (array $l): string => $l === [] ? 'none listed' : implode(', ', $l);

        return "## STACKS (the only source of truth for what is in scope)\n"
            ."CORE STACKS (strongest fit, lead with these, may claim freely): {$fmt($lists['core'])}.\n"
            ."SECONDARY STACKS (can do, partial fit: may mention as a general capability, but never present one as the core of a specific named past project unless that project's own facts list it): {$fmt($lists['secondary'])}.\n"
            ."EXCLUDED STACKS (out of scope: a job that core-requires one of these is a low score / no-bid, and none of these may ever be claimed): {$fmt($lists['excluded'])}.";
    }

    /**
     * @return array{min_budget: int, max_proposals: int, score_cutoff: int, stack_keywords: array<int, string>, hourly_floor: int, zero_history_budget_floor: int, red_flag_words: array<int, string>, followup_days: int, max_posted_age_days: int}
     */
    public function rules(): array
    {
        // Legacy consumers (analytics chart, OpenClaw context, score-now
        // command) asked for a single flat "keywords" list; core+secondary is
        // now that list - everything in scope you actually build.
        $stacks = $this->stackLists();

        return [
            'min_budget' => (int) $this->get('min_budget'),
            'max_proposals' => (int) $this->get('max_proposals'),
            'score_cutoff' => (int) $this->get('score_cutoff'),
            'stack_keywords' => array_values(array_unique(array_merge($stacks['core'], $stacks['secondary']))),
            'hourly_floor' => (int) $this->get('hourly_floor'),
            'zero_history_budget_floor' => (int) $this->get('zero_history_budget_floor'),
            'red_flag_words' => (array) $this->get('red_flag_words'),
            'followup_days' => (int) $this->get('followup_days'),
            'max_posted_age_days' => (int) $this->get('max_posted_age_days'),
        ];
    }
}
