<?php

namespace App\Http\Controllers;

use App\Models\AiCall;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Real spend, computed from the same ai_calls ledger every call already
 * writes to — never estimated, never a provider dashboard scrape. Admin
 * only: this is financial data, unlike the ungated /diagnostics page.
 *
 * "Remaining balance" is deliberately NOT pulled live from either
 * provider: confirmed live against both APIs that a normal key can't read
 * it (OpenAI's usage endpoint needs a separate Admin key with a scope
 * regular keys don't have; Anthropic has no such endpoint on a messages
 * key at all). Instead the operator enters what they funded, and
 * "remaining" is that minus the ledger's real, provable spend.
 */
class AiUsageController extends Controller
{
    /**
     * A full proposal run (draft + review + up to 2 revisions + a possible
     * surgical fix) is several ai_calls for one shipped proposal — grouped
     * here so "average cost per proposal" means the whole run, not one call.
     */
    protected const PROPOSAL_RUN_PURPOSES = ['proposal', 'proposal_review', 'proposal_revision', 'proposal_surgical_fix'];

    public function __invoke(Request $request, SettingsService $settings): JsonResponse
    {
        $provider = $request->query('provider');
        $provider = in_array($provider, ['anthropic', 'openai'], true) ? $provider : null;

        $scoped = fn () => AiCall::query()->when($provider, fn ($q) => $q->where('provider', $provider));

        $totalCalls = $scoped()->count();
        $successCalls = $scoped()->where('success', true)->count();

        $byProvider = AiCall::selectRaw('provider, count(*) as calls, sum(cost_usd) as cost')
            ->groupBy('provider')
            ->orderByDesc('cost')
            ->get()
            ->map(fn ($row) => ['provider' => $row->provider, 'calls' => (int) $row->calls, 'cost' => round((float) $row->cost, 4)]);

        $byPurpose = $scoped()
            ->selectRaw('purpose, count(*) as calls, sum(cost_usd) as cost')
            ->groupBy('purpose')
            ->orderByDesc('cost')
            ->get()
            ->map(fn ($row) => ['purpose' => $row->purpose, 'calls' => (int) $row->calls, 'cost' => round((float) $row->cost, 4)]);

        $proposalRuns = $scoped()->where('purpose', 'proposal')->count();
        $proposalTotalCost = $scoped()->whereIn('purpose', self::PROPOSAL_RUN_PURPOSES)->sum('cost_usd');

        return response()->json(['data' => [
            'provider_filter' => $provider,
            'total_spend' => round((float) $scoped()->sum('cost_usd'), 4),
            'spend_today' => round((float) $scoped()->whereDate('created_at', today())->sum('cost_usd'), 4),
            'spend_this_week' => round((float) $scoped()->where('created_at', '>=', now()->startOfWeek())->sum('cost_usd'), 4),
            'spend_this_month' => round((float) $scoped()->where('created_at', '>=', now()->startOfMonth())->sum('cost_usd'), 4),
            'total_calls' => $totalCalls,
            'success_rate' => $totalCalls > 0 ? round($successCalls / $totalCalls * 100, 1) : null,
            'avg_cost_per_scored_lead' => round((float) ($scoped()->where('purpose', 'scoring')->where('success', true)->avg('cost_usd') ?? 0), 4) ?: null,
            'avg_cost_per_proposal' => $proposalRuns > 0 ? round((float) $proposalTotalCost / $proposalRuns, 4) : null,
            'by_provider' => $byProvider,
            'by_purpose' => $byPurpose,
            'daily' => $this->dailySpend($provider),
            'balances' => $this->balances($settings),
            'cache_efficiency' => $this->cacheEfficiency($provider),
        ]]);
    }

    /**
     * What fraction of input tokens were served from a prompt-cache hit,
     * over the last 24h and last 30d. Proves the caching wired into
     * AnthropicProvider/OpenAiProvider is actually engaging, in real
     * numbers, instead of asking the operator to trust the code comment.
     *
     * The two providers report token fields with different meaning, so
     * they can't be summed blindly: Anthropic's input_tokens is the FRESH
     * (non-cached) portion only, with cached_tokens and cache_write_tokens
     * as separate pools — the true total is all three added together.
     * OpenAI's input_tokens is already the full prompt, with cached_tokens
     * as a subset of it (cache_write_tokens is always 0) — the true total
     * there is input_tokens alone. Each provider's true total is computed
     * with its own formula before being summed into the blended figure.
     *
     * @return array<string, array{cached_tokens: int, total_input_tokens: int, hit_rate_pct: ?float}>
     */
    protected function cacheEfficiency(?string $provider): array
    {
        $windows = ['last_24h' => now()->subDay(), 'last_30d' => now()->subDays(30)];

        return collect($windows)->map(function ($since) use ($provider) {
            $rows = AiCall::where('created_at', '>=', $since)
                ->where('success', true)
                ->when($provider, fn ($q) => $q->where('provider', $provider))
                ->selectRaw('provider, sum(input_tokens) as input_tokens, sum(cached_tokens) as cached_tokens, sum(cache_write_tokens) as cache_write_tokens')
                ->groupBy('provider')
                ->get();

            $totalInput = 0;
            $totalCached = 0;

            foreach ($rows as $row) {
                $totalInput += $row->provider === 'anthropic'
                    ? $row->input_tokens + $row->cached_tokens + $row->cache_write_tokens
                    : $row->input_tokens;
                $totalCached += (int) $row->cached_tokens;
            }

            return [
                'cached_tokens' => $totalCached,
                'total_input_tokens' => $totalInput,
                'hit_rate_pct' => $totalInput > 0 ? round($totalCached / $totalInput * 100, 1) : null,
            ];
        })->all();
    }

    /**
     * Last 30 days, zero-filled so the trend line never silently skips a
     * quiet day.
     *
     * @return array<int, array{date: string, cost: float}>
     */
    protected function dailySpend(?string $provider): array
    {
        $start = now()->subDays(29)->startOfDay();

        $rows = AiCall::where('created_at', '>=', $start)
            ->when($provider, fn ($q) => $q->where('provider', $provider))
            ->selectRaw('DATE(created_at) as date, sum(cost_usd) as cost')
            ->groupBy('date')
            ->pluck('cost', 'date');

        $days = [];

        for ($date = $start->copy(); $date->lte(now()); $date->addDay()) {
            $key = $date->toDateString();
            $days[] = ['date' => $key, 'cost' => round((float) ($rows[$key] ?? 0), 4)];
        }

        return $days;
    }

    /**
     * Operator-entered funded amount minus real ledger spend, per
     * provider, plus a burn rate (trailing 7-day average) so a shrinking
     * balance turns into an actionable "you have about N days left"
     * instead of just a shrinking number.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function balances(SettingsService $settings): array
    {
        $sevenDaysAgo = now()->subDays(7);

        return collect(['anthropic', 'openai'])->map(function (string $provider) use ($settings, $sevenDaysAgo) {
            $funded = (float) $settings->get("{$provider}_funded_total", 0);
            $spent = round((float) AiCall::where('provider', $provider)->sum('cost_usd'), 4);
            $remaining = round($funded - $spent, 4);

            $recentSpend = (float) AiCall::where('provider', $provider)->where('created_at', '>=', $sevenDaysAgo)->sum('cost_usd');
            $burnPerDay = round($recentSpend / 7, 4);

            return [
                'provider' => $provider,
                'funded' => $funded,
                'spent' => $spent,
                'remaining' => $remaining,
                'pct_remaining' => $funded > 0 ? max(0, min(100, round($remaining / $funded * 100, 1))) : null,
                'burn_rate_per_day' => $burnPerDay,
                'days_remaining' => $funded > 0 && $burnPerDay > 0 ? (int) floor(max(0, $remaining) / $burnPerDay) : null,
            ];
        })->all();
    }
}
