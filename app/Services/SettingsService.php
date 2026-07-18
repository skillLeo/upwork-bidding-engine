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

        // AI engine — OpenClaw, on its own Claude Code CLI subscription auth.
        // No Anthropic API key/model here: OpenClaw is already authenticated,
        // and the CLI picks its own model.
        'openclaw_url' => ['group' => 'openclaw', 'secret' => true, 'default' => ''],
        'openclaw_token' => ['group' => 'openclaw', 'secret' => true, 'default' => ''],
        'ai_engine_enabled' => ['group' => 'openclaw', 'secret' => false, 'default' => true],

        // WhatsApp — sent via OpenClaw (already QR-linked to WhatsApp), not
        // Meta's Cloud API, so the only thing this app needs to know is who
        // to message. openclaw_url/openclaw_token above do the connecting.
        'bidder_whatsapp' => ['group' => 'whatsapp', 'secret' => true, 'default' => ''],

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
        'scoring_system_prompt' => ['group' => 'ai', 'secret' => false, 'default' => ''],
        'proposal_system_prompt' => ['group' => 'ai', 'secret' => false, 'default' => ''],

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

    public function openClawUrl(): ?string
    {
        return $this->get('openclaw_url') ?: null;
    }

    public function openClawToken(): ?string
    {
        return $this->get('openclaw_token') ?: null;
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
