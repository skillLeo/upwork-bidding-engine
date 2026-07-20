<?php

namespace App\Services;

use App\Models\Setting;
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
        'gmail_address' => ['group' => 'vollna', 'secret' => false, 'default' => 'skillleopvt@gmail.com'],
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
        // Canonical fact sheet — the only source of truth about Hassam's
        // track record. Anything not derivable from this must never be
        // claimed in a proposal.
        'project_facts' => ['group' => 'ai', 'secret' => false, 'default' => 'PatrolTick: guard-management SaaS. Laravel + Vue web, Flutter mobile, PostgreSQL. Built the REST API the mobile app runs on, auth + role-based access, reporting, offline guard check-in sync.'
            ."\nSkillLeo Financial: multi-tenant finance SaaS. Laravel backend + Flutter app. Tenant model, billing, dashboards."
            ."\nRouteHealth: healthcare platform. Next.js + Laravel."
            ."\niiBSOOR: e-commerce app for the Somali market. Flutter + Laravel. RTL layout."
            ."\nMy EXtreme Trainer: fitness platform. Laravel + Next.js."
            ."\nOdoo migrations: large Odoo-to-Odoo data migrations over XML-RPC. Batched record transfer, custom field mapping, attachment migration, resume after interruption. This was DATA MIGRATION, not an inventory sync system. Never describe it as anything else."
            ."\nSkillLeo Bidding Engine: this system. Laravel + Vue."
            ."\nStack: PHP, Laravel, Python, Django, FastAPI, Odoo, React, Next.js, Vue, Node, Express, MERN, TypeScript, Flutter, React Native, Dart, MySQL, PostgreSQL, Firebase."
            ."\nAnything not on this sheet is NOT in the track record and must never be claimed. Magento is not on this sheet."],

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
        'proposal_signature' => ['group' => 'ai', 'secret' => false, 'default' => 'Hassam'],
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
            'holistic', 'efficiently', 'efficient',
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

        // Rules
        'min_budget' => ['group' => 'rules', 'secret' => false, 'default' => 150],
        'max_proposals' => ['group' => 'rules', 'secret' => false, 'default' => 25],
        'score_cutoff' => ['group' => 'rules', 'secret' => false, 'default' => 7],
        'stack_keywords' => ['group' => 'rules', 'secret' => false, 'default' => ['Laravel', 'PHP', 'Next.js', 'React', 'Node', 'MERN', 'Flutter', 'Python']],
        'hourly_floor' => ['group' => 'rules', 'secret' => false, 'default' => 8],
        'zero_history_budget_floor' => ['group' => 'rules', 'secret' => false, 'default' => 100],
        'red_flag_words' => ['group' => 'rules', 'secret' => false, 'default' => ['free test', 'unpaid sample', 'urgent no budget', 'revenue share only']],
        'followup_days' => ['group' => 'rules', 'secret' => false, 'default' => 3],
        // Separate from the rubric's 7-day auto-reject: a 3-day-old 8/10
        // still gets scored and written (visible on the dashboard), but a
        // lead older than this at scoring time doesn't ring the phone —
        // fresh leads are the whole bidding strategy. 0 disables the gate.
        'notification_freshness_hours' => ['group' => 'rules', 'secret' => false, 'default' => 48],
        // A stale posting is a spend-saving pre-check, not a visibility
        // rule — a lead that fails this still saves, still shows up in the
        // dashboard as Archived, and is never deleted. It only skips the
        // paid AI call for a job that's realistically already gone.
        'max_posted_age_days' => ['group' => 'rules', 'secret' => false, 'default' => 7],
    ];

    public const SERVICES = ['vollna', 'openclaw', 'whatsapp', 'mail', 'anthropic', 'openai'];

    /**
     * All settings, decrypted, keyed by setting key. Cached forever;
     * invalidated on every write via forgetCache().
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        // Cache a plain array, never Eloquent models/collections — caching ORM
        // objects through Redis serialization is fragile (lazy-loading state,
        // connection resolvers) and unnecessary when all we need is scalars.
        $rows = Cache::rememberForever(self::CACHE_KEY, function () {
            return Setting::query()
                ->get(['key', 'value', 'is_secret'])
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

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default ?? self::SCHEMA[$key]['default'] ?? null;
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
        $encoded = $this->encode($value, $meta['secret']);

        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $encoded, 'group' => $meta['group'], 'is_secret' => $meta['secret']],
        );

        $this->forgetCache();
    }

    /**
     * Persist many settings at once (Settings page "Save" does one round trip).
     *
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::SCHEMA)) {
                continue;
            }

            $meta = self::SCHEMA[$key];

            Setting::query()->updateOrCreate(
                ['key' => $key],
                [
                    'value' => $this->encode($value, $meta['secret']),
                    'group' => $meta['group'],
                    'is_secret' => $meta['secret'],
                ],
            );
        }

        $this->forgetCache();
    }

    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
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
     * @return array{min_budget: int, max_proposals: int, score_cutoff: int, stack_keywords: array<int, string>, hourly_floor: int, zero_history_budget_floor: int, red_flag_words: array<int, string>, followup_days: int, max_posted_age_days: int}
     */
    public function rules(): array
    {
        return [
            'min_budget' => (int) $this->get('min_budget'),
            'max_proposals' => (int) $this->get('max_proposals'),
            'score_cutoff' => (int) $this->get('score_cutoff'),
            'stack_keywords' => (array) $this->get('stack_keywords'),
            'hourly_floor' => (int) $this->get('hourly_floor'),
            'zero_history_budget_floor' => (int) $this->get('zero_history_budget_floor'),
            'red_flag_words' => (array) $this->get('red_flag_words'),
            'followup_days' => (int) $this->get('followup_days'),
            'max_posted_age_days' => (int) $this->get('max_posted_age_days'),
        ];
    }
}
