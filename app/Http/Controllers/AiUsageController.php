<?php

namespace App\Http\Controllers;

use App\Models\AiCall;
use Illuminate\Http\JsonResponse;

/**
 * Real spend, computed from the same ai_calls ledger every call already
 * writes to — never estimated, never a provider dashboard scrape. Admin
 * only: this is financial data, unlike the ungated /diagnostics page.
 */
class AiUsageController extends Controller
{
    /**
     * A full proposal run (draft + review + up to 2 revisions + a possible
     * surgical fix) is several ai_calls for one shipped proposal — grouped
     * here so "average cost per proposal" means the whole run, not one call.
     */
    protected const PROPOSAL_RUN_PURPOSES = ['proposal', 'proposal_review', 'proposal_revision', 'proposal_surgical_fix'];

    public function __invoke(): JsonResponse
    {
        $totalCalls = AiCall::count();
        $successCalls = AiCall::where('success', true)->count();

        $byProvider = AiCall::selectRaw('provider, count(*) as calls, sum(cost_usd) as cost')
            ->groupBy('provider')
            ->orderByDesc('cost')
            ->get()
            ->map(fn ($row) => ['provider' => $row->provider, 'calls' => (int) $row->calls, 'cost' => round((float) $row->cost, 4)]);

        $byPurpose = AiCall::selectRaw('purpose, count(*) as calls, sum(cost_usd) as cost')
            ->groupBy('purpose')
            ->orderByDesc('cost')
            ->get()
            ->map(fn ($row) => ['purpose' => $row->purpose, 'calls' => (int) $row->calls, 'cost' => round((float) $row->cost, 4)]);

        $proposalRuns = AiCall::where('purpose', 'proposal')->count();
        $proposalTotalCost = AiCall::whereIn('purpose', self::PROPOSAL_RUN_PURPOSES)->sum('cost_usd');

        return response()->json(['data' => [
            'total_spend' => round((float) AiCall::sum('cost_usd'), 4),
            'spend_today' => round((float) AiCall::whereDate('created_at', today())->sum('cost_usd'), 4),
            'spend_this_week' => round((float) AiCall::where('created_at', '>=', now()->startOfWeek())->sum('cost_usd'), 4),
            'spend_this_month' => round((float) AiCall::where('created_at', '>=', now()->startOfMonth())->sum('cost_usd'), 4),
            'total_calls' => $totalCalls,
            'success_rate' => $totalCalls > 0 ? round($successCalls / $totalCalls * 100, 1) : null,
            'avg_cost_per_scored_lead' => round((float) (AiCall::where('purpose', 'scoring')->where('success', true)->avg('cost_usd') ?? 0), 4) ?: null,
            'avg_cost_per_proposal' => $proposalRuns > 0 ? round((float) $proposalTotalCost / $proposalRuns, 4) : null,
            'by_provider' => $byProvider,
            'by_purpose' => $byPurpose,
            'daily' => $this->dailySpend(),
        ]]);
    }

    /**
     * Last 30 days, zero-filled so the trend line never silently skips a
     * quiet day.
     *
     * @return array<int, array{date: string, cost: float}>
     */
    protected function dailySpend(): array
    {
        $start = now()->subDays(29)->startOfDay();

        $rows = AiCall::where('created_at', '>=', $start)
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
}
