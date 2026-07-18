<?php

namespace App\Services\Ai;

use App\Models\ActivityLog;
use App\Models\AiCall;
use App\Services\OpsAlertService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;

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
    ) {}

    public function complete(string $purpose, string $systemPrompt, string $userContent, string $model, int $maxTokens, ?int $leadId = null): AiResponse
    {
        $primary = $this->provider($this->settings->get('ai_provider', 'anthropic'));
        $secondary = $primary->name() === 'anthropic' ? $this->openAi : $this->anthropic;

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

    protected function attempt(AiProvider $provider, string $purpose, string $system, string $user, string $model, int $maxTokens, ?int $leadId): AiResponse
    {
        if (! $provider->isConfigured()) {
            throw new \RuntimeException(
                ucfirst($provider->name())." API key is not set — add it in Settings → AI models & prompts."
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

    /**
     * Model IDs don't cross providers, so a failed-over call maps to the
     * secondary's equivalent tier: cheap-and-fast for scoring, stronger
     * for proposals.
     */
    protected function equivalentModel(AiProvider $provider, string $purpose): string
    {
        if ($provider->name() === 'openai') {
            return $purpose === 'proposal' ? 'gpt-4o' : 'gpt-4o-mini';
        }

        return $purpose === 'proposal' ? 'claude-sonnet-5' : 'claude-haiku-4-5';
    }

    protected function provider(string $name): AiProvider
    {
        return $name === 'openai' ? $this->openAi : $this->anthropic;
    }
}
