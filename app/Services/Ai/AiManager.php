<?php

namespace App\Services\Ai;

use App\Exceptions\AiQuotaExceededException;
use App\Mail\LeadNotificationMail;
use App\Models\ActivityLog;
use App\Models\AiCall;
use App\Models\Tenant;
use App\Services\OpsAlertService;
use App\Services\SettingsService;
use App\Tenancy\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * The one entry point every AI call goes through. Owns three concerns the
 * providers deliberately don't: choosing the provider (settings-driven,
 * with try/catch failover — not intelligence), logging every call's real
 * token usage and cost to ai_calls, and alerting the operator once per
 * failover incident.
 *
 * Failover: 3 consecutive primary failures switch to the secondary for
 * 15 minutes, then the primary is tried again automatically (auto-return).
 * Because model IDs are provider-specific, the failed-over call runs on
 * the secondary's equivalent tier, not the configured model ID.
 */
class AiManager
{
    public const FAILOVER_UNTIL_KEY = 'ai:failover_until';

    public const FAILS_KEY = 'ai:consecutive_failures';

    public const ALERTED_KEY = 'ai:failover_alerted';

    public const FAILS_BEFORE_FAILOVER = 3;

    public const FAILOVER_MINUTES = 15;

    public function __construct(
        protected SettingsService $settings,
        protected AnthropicProvider $anthropic,
        protected OpenAiProvider $openAi,
        protected OpsAlertService $alerts,
        protected AiQuotaService $quota,
    ) {}

    public function complete(string $purpose, string $systemPrompt, string $userContent, string $model, int $maxTokens, ?int $leadId = null): AiResponse
    {
        $this->enforceQuota();

        // PLATFORM-LEVEL, for every tenant's calls (P8). The platform pays for
        // AI out of one pooled set of keys, so the provider and the models are
        // the platform's choice, identical in every workspace. What IS per
        // tenant is the spend limit — enforceQuota() above ran first and
        // charged this call against THIS workspace's own ai_monthly_token_cap.
        $primary = $this->provider($this->settings->platform('ai_provider', 'anthropic'));
        $secondary = $primary->name() === 'anthropic' ? $this->openAi : $this->anthropic;

        // Model IDs are provider-specific. If the configured model belongs
        // to the OTHER provider (e.g. the operator switched provider but a
        // Claude model ID is still stored), silently sending it would fail
        // 3x and boomerang to failover — instead map to this provider's
        // equivalent tier so a provider switch genuinely takes effect.
        $model = $this->resolveModel($primary, $model, $purpose);

        if ($this->failoverActive() && $secondary->isConfigured()) {
            return $this->attempt($secondary, $purpose, $systemPrompt, $userContent, $this->equivalentModel($secondary, $purpose), $maxTokens, $leadId);
        }

        try {
            $response = $this->attempt($primary, $purpose, $systemPrompt, $userContent, $model, $maxTokens, $leadId);
        } catch (\Throwable $e) {
            $fails = (int) Cache::increment(self::FAILS_KEY);

            if ($fails < self::FAILS_BEFORE_FAILOVER || ! $secondary->isConfigured()) {
                throw $e;
            }

            $this->activateFailover($primary->name(), $secondary->name(), $e);

            return $this->attempt($secondary, $purpose, $systemPrompt, $userContent, $this->equivalentModel($secondary, $purpose), $maxTokens, $leadId);
        }

        // Primary healthy again: reset the streak, and if we're returning
        // from a failover window, log the recovery (silently — one alert
        // per incident means no "all clear" spam).
        Cache::forget(self::FAILS_KEY);

        if (Cache::has(self::ALERTED_KEY)) {
            Cache::forget(self::ALERTED_KEY);
            ActivityLog::record('ai_provider_recovered', meta: ['provider' => $primary->name()]);
        }

        return $response;
    }

    /**
     * P5 AI quotas — checked BEFORE any provider call, so a refused call
     * never reaches the ledger and never spends a token.
     *
     * Two independent gates: a suspended/past_due tenant is refused
     * unconditionally (no polling, no AI spend — a billing decision, not a
     * usage one); an over-cap tenant is refused only when the workspace
     * itself turned ai_hard_stop_on_cap on. The 80% warning fires on the
     * way IN, before either gate can block the call that would have
     * crossed it, so the owner is warned before the very call that trips
     * the hard stop, not after.
     */
    protected function enforceQuota(): void
    {
        $tenant = Tenancy::current();

        if ($tenant === null) {
            return; // console/tinker context with no tenant bound — nothing to gate.
        }

        if ($tenant->isBillingBlocked()) {
            throw new \RuntimeException(
                'This workspace is suspended or past due — AI calls are paused until billing is resolved.'
            );
        }

        if ($this->quota->shouldAlertAt80Percent($tenant->id)) {
            $this->notifyOwnerOfQuota($tenant, $this->quota->summary());
        }

        if ($this->quota->shouldRefuseCalls()) {
            throw new AiQuotaExceededException($this->quota->capTokens(), $this->quota->tokensUsedThisMonth());
        }
    }

    /**
     * Best-effort direct email to the owner specifically — not the tenant-wide
     * bell (AppNotification has no per-user targeting today), because the
     * spec asks to notify THE OWNER, not the workspace at large.
     */
    protected function notifyOwnerOfQuota(Tenant $tenant, array $summary): void
    {
        $owner = $tenant->owner;

        if ($owner === null) {
            return;
        }

        try {
            Mail::to($owner->email)->queue(new LeadNotificationMail(
                'AI usage at '.$summary['percent'].'% of this month\'s cap',
                sprintf(
                    'This workspace has used %s of its %s token monthly AI cap. %s',
                    number_format($summary['used']),
                    number_format($summary['cap']),
                    $summary['hard_stop']
                        ? 'The hard stop is ON — scoring and proposal writing will pause at 100%.'
                        : 'The hard stop is off, so calls will keep flowing past 100%.',
                ),
                '/settings?section=ai-usage',
                (string) config('app.url'),
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function attempt(AiProvider $provider, string $purpose, string $system, string $user, string $model, int $maxTokens, ?int $leadId): AiResponse
    {
        if (! $provider->isConfigured()) {
            throw new \RuntimeException(
                ucfirst($provider->name()).' API key is not set — add it in Settings → AI models & prompts.'
            );
        }

        $startedAt = microtime(true);

        try {
            $response = $provider->complete($system, $user, $model, $maxTokens);
        } catch (\Throwable $e) {
            AiCall::create([
                'purpose' => $purpose,
                'lead_id' => $leadId,
                'provider' => $provider->name(),
                'model' => $model,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'success' => false,
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            throw $e;
        }

        AiCall::create([
            'purpose' => $purpose,
            'lead_id' => $leadId,
            'provider' => $response->provider,
            'model' => $response->model,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cached_tokens' => $response->cachedTokens,
            'cache_write_tokens' => $response->cacheWriteTokens,
            'cost_usd' => $response->costUsd,
            'duration_ms' => $response->durationMs,
            'success' => true,
        ]);

        return $response;
    }

    protected function activateFailover(string $from, string $to, \Throwable $cause): void
    {
        Cache::put(self::FAILOVER_UNTIL_KEY, now()->addMinutes(self::FAILOVER_MINUTES)->toIso8601String(), now()->addMinutes(self::FAILOVER_MINUTES));
        Cache::forget(self::FAILS_KEY);

        ActivityLog::record('ai_provider_failover', meta: [
            'from' => $from,
            'to' => $to,
            'error' => $cause->getMessage(),
        ]);

        if (! Cache::has(self::ALERTED_KEY)) {
            Cache::forever(self::ALERTED_KEY, now()->toIso8601String());
            // Best effort — failover must not depend on the alert landing.
            $this->alerts->send(sprintf(
                "🔶 SkillLeo: %s failed %d times in a row — AI calls switched to %s for ~%d minutes.\n\nLast error: %s\n\nIt returns to %s automatically once it recovers.",
                ucfirst($from),
                self::FAILS_BEFORE_FAILOVER,
                ucfirst($to),
                self::FAILOVER_MINUTES,
                $cause->getMessage(),
                ucfirst($from),
            ));
        }
    }

    protected function failoverActive(): bool
    {
        return Cache::has(self::FAILOVER_UNTIL_KEY);
    }

    protected function resolveModel(AiProvider $provider, string $model, string $purpose): string
    {
        if ($provider->name() === 'openai' && ! str_starts_with($model, 'gpt-')) {
            return $this->equivalentModel($provider, $purpose);
        }

        if ($provider->name() === 'anthropic' && ! str_starts_with($model, 'claude-')) {
            return $this->equivalentModel($provider, $purpose);
        }

        return $model;
    }

    /**
     * Model IDs don't cross providers, so a failed-over call maps to the
     * secondary's equivalent tier: cheap-and-fast for scoring, stronger
     * for anything proposal-shaped (draft, review, revision, surgical fix
     * all carry writing/judgment weight).
     */
    protected function equivalentModel(AiProvider $provider, string $purpose): string
    {
        $strong = str_starts_with($purpose, 'proposal');

        if ($provider->name() === 'openai') {
            return $strong ? 'gpt-4o' : 'gpt-4o-mini';
        }

        return $strong ? 'claude-sonnet-5' : 'claude-haiku-4-5';
    }

    protected function provider(string $name): AiProvider
    {
        return $name === 'openai' ? $this->openAi : $this->anthropic;
    }
}
