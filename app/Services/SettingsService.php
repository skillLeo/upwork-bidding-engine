<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

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
        // Vollna
        'vollna_webhook_secret' => ['group' => 'vollna', 'secret' => true, 'default' => ''],

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

    public const SERVICES = ['vollna', 'openclaw', 'whatsapp'];

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

    public function vollnaWebhookSecret(): ?string
    {
        return $this->get('vollna_webhook_secret') ?: null;
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
