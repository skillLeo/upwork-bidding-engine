<?php

namespace App\Services\Ai;

use App\Models\AiCall;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;

/**
 * P5 AI quotas — per tenant, computed LIVE from the ai_calls ledger for the
 * current calendar month rather than a running counter, so it can never
 * drift from the truth (the same "compute from the ledger, don't trust a
 * cached total" instinct AiUsageSection already uses for spend). Read by
 * AiManager before every call, by DiagnosticsController for the banner, and
 * by the platform console's per-tenant health tile.
 */
class AiQuotaService
{
    public function __construct(protected SettingsService $settings) {}

    public function capTokens(): int
    {
        return max(0, (int) $this->settings->get('ai_monthly_token_cap', 0));
    }

    public function hardStopEnabled(): bool
    {
        return (bool) $this->settings->get('ai_hard_stop_on_cap', false);
    }

    /** 0 = no cap set, i.e. unlimited. */
    public function isCapped(): bool
    {
        return $this->capTokens() > 0;
    }

    public function tokensUsedThisMonth(): int
    {
        return (int) AiCall::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('COALESCE(SUM(input_tokens + output_tokens), 0) as total')
            ->value('total');
    }

    /** 0-100. 0 when there is no cap — "percent of unlimited" is meaningless. */
    public function percentUsed(): float
    {
        if (! $this->isCapped()) {
            return 0.0;
        }

        return min(100.0, round(($this->tokensUsedThisMonth() / $this->capTokens()) * 100, 1));
    }

    public function isOverCap(): bool
    {
        return $this->isCapped() && $this->tokensUsedThisMonth() >= $this->capTokens();
    }

    /** The single call site AiManager gates on. */
    public function shouldRefuseCalls(): bool
    {
        return $this->hardStopEnabled() && $this->isOverCap();
    }

    /**
     * True the FIRST time this is called after crossing 80% in a given
     * calendar month for a given tenant — a Cache flag (TTL to month end)
     * dedupes so the owner is notified once, not on every scoring call for
     * the rest of the month.
     */
    public function shouldAlertAt80Percent(int $tenantId): bool
    {
        if ($this->percentUsed() < 80.0) {
            return false;
        }

        $key = "ai:quota_80_alerted:{$tenantId}:".now()->format('Y-m');

        return Cache::add($key, true, now()->endOfMonth());
    }

    /**
     * @return array{cap: int, used: int, percent: float, hard_stop: bool, over_cap: bool}
     */
    public function summary(): array
    {
        return [
            'cap' => $this->capTokens(),
            'used' => $this->tokensUsedThisMonth(),
            'percent' => $this->percentUsed(),
            'hard_stop' => $this->hardStopEnabled(),
            'over_cap' => $this->isOverCap(),
        ];
    }
}
