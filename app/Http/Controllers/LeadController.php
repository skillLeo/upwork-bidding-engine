<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Enums\ClientStage;
use App\Enums\LeadStatus;
use App\Http\Requests\Leads\UpdateLeadStatusRequest;
use App\Http\Resources\LeadResource;
use App\Jobs\ScoreLeadJob;
use App\Jobs\VollnaSyncJob;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Lead;
use App\Services\LeadFilterEvaluator;
use App\Services\NlSearchParser;
use App\Services\OpenClawService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    public function __construct(
        protected LeadFilterEvaluator $filterEvaluator,
        protected NlSearchParser $nlSearchParser,
        protected OpenClawService $openClaw,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Lead::query()->with('client');

        if ($status = $request->query('status')) {
            $statuses = array_values(array_filter(explode(',', (string) $status)));
            $query->whereIn('status', $statuses);
        }

        if ($request->filled('score_min')) {
            $query->where('score', '>=', (int) $request->query('score_min'));
        }

        // A top-level page control (like status/score), not a saved-filter
        // criterion — always applied, including during search, so switching
        // date ranges doesn't get silently ignored by a search term.
        if ($postedFrom = $request->query('posted_from')) {
            $query->where('posted_at', '>=', $postedFrom);
        }

        if ($postedTo = $request->query('posted_to')) {
            $query->where('posted_at', '<=', $postedTo);
        }

        // Searching means "find this across everything", not "search within
        // my current filter" - a saved filter's keyword/budget/etc. rules
        // are skipped entirely while a search term is present. The results
        // are still annotated below so leads that wouldn't normally pass the
        // active filter show up with a reason instead of just disappearing.
        $hasSearch = $request->filled('search');
        $searchChips = [];

        if ($search = $request->query('search')) {
            $searchChips = $this->applySearch($query, $search);
        }

        $criteria = $this->criteriaFromRequest($request);

        if (! $hasSearch) {
            $this->applyCriteria($query, $criteria);
        }

        $sort = (string) $request->query('sort', '-created_at');

        if (ltrim($sort, '-') === 'attention') {
            // Default browse order: still-unbid ready leads surface first,
            // highest score next, freshest last — matches actual bidding
            // priority (a 9/10 posted 20 minutes ago beats a 7/10 from
            // yesterday) instead of raw intake order.
            $query->orderByRaw("status = 'ready' desc")
                ->orderByDesc('score')
                ->orderByDesc('posted_at');
        } else {
            $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
            $column = ltrim($sort, '-');

            if (! in_array($column, ['created_at', 'score', 'posted_at', 'proposal_count', 'budget_max', 'connects_required'], true)) {
                $column = 'created_at';
            }

            $query->orderBy($column, $direction)->orderBy('id', 'desc');
        }

        $perPage = (int) $request->query('per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 100) : 20;

        $leads = $query->paginate($perPage)->withQueryString();

        // Only worth evaluating (and worth the extra per-lead work) when a
        // filter's criteria are actually in play - a plain unfiltered browse
        // has nothing to be "not in filter" relative to.
        if ($criteria !== []) {
            foreach ($leads->items() as $lead) {
                $reasons = $this->filterEvaluator->reasons($lead, $criteria);
                $lead->setAttribute('matches_filter', $reasons === []);
                $lead->setAttribute('filter_fail_reasons', $reasons);
            }
        }

        return response()->json([
            'data' => LeadResource::collection($leads->items()),
            'meta' => [
                'current_page' => $leads->currentPage(),
                'last_page' => $leads->lastPage(),
                'per_page' => $leads->perPage(),
                'total' => $leads->total(),
                'search_chips' => $searchChips,
            ],
        ]);
    }

    /**
     * Search box entry point: try the free (zero-token) pattern parser
     * first, fall back to OpenClaw only when it recognized nothing at all,
     * and fall back further to a plain keyword LIKE if OpenClaw is
     * unreachable/unconfigured/erroring — the search box must never hang or
     * come back empty just because the parser or OpenClaw had a bad day.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Lead>  $query
     * @return array<int, array{label: string, phrase: ?string}>
     */
    protected function applySearch($query, string $search): array
    {
        $parsed = $this->nlSearchParser->parse($search);

        if ($parsed['understood']) {
            $this->applyNlCriteria($query, $parsed['criteria']);

            return $parsed['chips'];
        }

        $aiCriteria = $this->tryAiSearchFallback($search);

        if ($aiCriteria !== null) {
            $this->applyNlCriteria($query, $aiCriteria);

            return $this->chipsFromCriteria($aiCriteria);
        }

        $query->where(function ($q) use ($search) {
            $q->where('title', 'like', "%{$search}%")
                ->orWhere('full_brief', 'like', "%{$search}%");
        });

        return [];
    }

    /**
     * Only reached when the free parser found nothing at all (a genuinely
     * odd query). The AI call runs ~10-15s through the OpenClaw CLI, so
     * successful parses are cached — a repeated or re-typed query answers
     * instantly instead of paying the agent round-trip again. Failures are
     * deliberately NOT cached: a Mac that was asleep shouldn't leave a
     * stale "AI can't do this" verdict pinned to a query for an hour.
     *
     * @return array<string, mixed>|null
     */
    protected function tryAiSearchFallback(string $search): ?array
    {
        $cacheKey = 'nl-search:'.md5(mb_strtolower(trim($search)));

        if (Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);

            return $cached === [] ? null : $cached;
        }

        if (! $this->openClaw->isReachable()) {
            return null;
        }

        try {
            $criteria = $this->openClaw->parseSearchQuery($search);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        // A real parse sticks for an hour; "understood nothing" only
        // briefly, so an improved skill (or a query that only failed by
        // luck) gets retried soon rather than tomorrow.
        Cache::put($cacheKey, $criteria, $criteria === [] ? 300 : 3600);

        return $criteria !== [] ? $criteria : null;
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<int, array{label: string, phrase: ?string}>
     */
    protected function chipsFromCriteria(array $criteria): array
    {
        $chips = [];

        foreach ($criteria as $key => $value) {
            if ($value === null || $value === [] || $value === false) {
                continue;
            }

            $label = is_array($value) ? implode(', ', $value) : (string) $value;
            // No matched substring to splice out of the search box for an
            // AI-derived chip (unlike the pattern parser's chips) - the
            // frontend clears the whole search box instead when phrase is null.
            $chips[] = ['label' => str_replace('_', ' ', $key).': '.$label, 'phrase' => null];
        }

        return $chips;
    }

    /**
     * Applies the search box's parsed criteria as the query's actual
     * restriction (AND-combined, same as applyCriteria) — deliberately
     * separate from applyCriteria/LeadFilterEvaluator, which stay scoped to
     * the ACTIVE SAVED FILTER's own criteria for the "why doesn't this match
     * my filter" annotation. Search criteria restrict the result set
     * directly; they're never just advisory.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Lead>  $query
     * @param  array<string, mixed>  $criteria
     */
    protected function applyNlCriteria($query, array $criteria): void
    {
        $this->applyCriteria($query, array_intersect_key($criteria, array_flip([
            'include_keywords', 'exclude_keywords', 'budget_min', 'budget_max',
            'payment_verified_only', 'min_client_spend', 'client_countries_include',
            'posted_within_minutes',
        ])));

        if (isset($criteria['budget_type'])) {
            $query->where('budget_type', $criteria['budget_type']);
        }

        if (isset($criteria['score_min'])) {
            $query->where('score', '>=', $criteria['score_min']);
        }

        if (isset($criteria['proposal_max'])) {
            $query->where('proposal_count', '<=', $criteria['proposal_max']);
        }

        if (isset($criteria['connects_max'])) {
            $query->where(function ($q) use ($criteria) {
                $q->whereNull('connects_required')->orWhere('connects_required', '<=', $criteria['connects_max']);
            });
        }

        if (isset($criteria['hire_rate_min'])) {
            $query->where('client_hire_rate_pct', '>=', $criteria['hire_rate_min']);
        }

        if (! empty($criteria['is_favorite'])) {
            $query->where('is_favorite', true);
        }

        if ($statusIn = $criteria['status_in'] ?? []) {
            $query->whereIn('status', $statusIn);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function criteriaFromRequest(Request $request): array
    {
        $criteria = [
            'include_keywords' => $this->queryList($request, 'include_keywords'),
            'exclude_keywords' => $this->queryList($request, 'exclude_keywords'),
            'budget_min' => $request->filled('budget_min') ? (float) $request->query('budget_min') : null,
            'budget_max' => $request->filled('budget_max') ? (float) $request->query('budget_max') : null,
            'payment_verified_only' => $request->boolean('payment_verified_only'),
            'min_client_spend' => $request->filled('min_client_spend') ? (float) $request->query('min_client_spend') : null,
            'client_countries_include' => $this->queryList($request, 'client_countries_include'),
            'client_countries_exclude' => $this->queryList($request, 'client_countries_exclude'),
            'posted_within_minutes' => $request->filled('posted_within_minutes') ? (int) $request->query('posted_within_minutes') : null,
        ];

        // Drop empty/false/null entries so an unfiltered browse (nothing set)
        // ends up as `[]`, the signal used above to skip evaluation entirely.
        return array_filter($criteria, fn ($value) => $value !== null && $value !== [] && $value !== false);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Lead>  $query
     * @param  array<string, mixed>  $criteria
     */
    protected function applyCriteria($query, array $criteria): void
    {
        if ($include = $criteria['include_keywords'] ?? []) {
            $query->where(function ($q) use ($include) {
                foreach ($include as $keyword) {
                    $q->orWhere('title', 'like', "%{$keyword}%")
                        ->orWhere('full_brief', 'like', "%{$keyword}%");
                }
            });
        }

        if ($exclude = $criteria['exclude_keywords'] ?? []) {
            $query->where(function ($q) use ($exclude) {
                foreach ($exclude as $keyword) {
                    $q->where('title', 'not like', "%{$keyword}%")
                        ->where('full_brief', 'not like', "%{$keyword}%");
                }
            });
        }

        if (isset($criteria['budget_min'])) {
            // A lead with no parsed budget can't be excluded by a floor it
            // never reported meeting — only leads with a KNOWN budget below
            // the floor get filtered out.
            $query->where(function ($q) use ($criteria) {
                $q->whereNull('budget_max')->orWhere('budget_max', '>=', $criteria['budget_min']);
            });
        }

        if (isset($criteria['budget_max'])) {
            $query->where(function ($q) use ($criteria) {
                $q->whereNull('budget_min')->orWhere('budget_min', '<=', $criteria['budget_max']);
            });
        }

        if (! empty($criteria['payment_verified_only'])) {
            $query->where('payment_verified', true);
        }

        if (isset($criteria['min_client_spend'])) {
            $query->where('client_spend_amount', '>=', $criteria['min_client_spend']);
        }

        if ($countriesIn = $criteria['client_countries_include'] ?? []) {
            $query->whereIn('client_country', $countriesIn);
        }

        if ($countriesOut = $criteria['client_countries_exclude'] ?? []) {
            $query->whereNotIn('client_country', $countriesOut);
        }

        if (isset($criteria['posted_within_minutes'])) {
            $query->where('posted_at', '>=', now()->subMinutes($criteria['posted_within_minutes']));
        }
    }

    public function show(Request $request, Lead $lead): JsonResponse
    {
        $lead->load('client');

        // Only present when the frontend links here from a filtered context
        // (e.g. clicking a "Not in filter" search result) - see index() for
        // why this is skipped entirely on a plain, unfiltered lead view.
        $criteria = $this->criteriaFromRequest($request);
        if ($criteria !== []) {
            $reasons = $this->filterEvaluator->reasons($lead, $criteria);
            $lead->setAttribute('matches_filter', $reasons === []);
            $lead->setAttribute('filter_fail_reasons', $reasons);
        }

        return response()->json(['data' => new LeadResource($lead)]);
    }

    /**
     * Saved filter criteria arrays arrive as either `key[]=a&key[]=b` or a
     * single comma-separated `key=a,b` (simpler to build client-side) -
     * accept both instead of forcing the frontend into one array format.
     *
     * @return array<int, string>
     */
    protected function queryList(Request $request, string $key): array
    {
        $value = $request->query($key);

        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value)));
        }

        if (is_string($value) && $value !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }

        return [];
    }

    public function updateStatus(UpdateLeadStatusRequest $request, Lead $lead): JsonResponse
    {
        $oldStatus = $lead->status;
        $newStatus = LeadStatus::from($request->validated('status'));

        $lead->update(['status' => $newStatus]);

        ActivityLog::record(ActivityType::LeadStatusUpdated, subject: $lead, meta: [
            'from' => $oldStatus->value,
            'to' => $newStatus->value,
        ], userId: $request->user()?->id);

        if ($newStatus === LeadStatus::Sent) {
            ActivityLog::record(ActivityType::ProposalSent, subject: $lead, userId: $request->user()?->id);
        }

        // First forward transition past "ready" is what turns a lead into a real
        // client relationship — provision the Client record here if missing so
        // Client Memory has somewhere to attach the conversation.
        if (! $lead->client_id && in_array($newStatus, [LeadStatus::Sent, LeadStatus::Replied, LeadStatus::Won], true)) {
            $client = Client::create([
                'name' => $lead->title,
                'lead_id' => $lead->id,
                'budget_discussed' => $lead->budget,
                'stage' => ClientStage::New,
            ]);

            $lead->update(['client_id' => $client->id]);
        }

        return response()->json(['data' => new LeadResource($lead->fresh('client'))]);
    }

    /**
     * Account-wide, not per-user — same reasoning as saved filters and lead
     * status: this is a single-bidder tool, not multi-tenant. Lets a bidder
     * flag a lead as worth prioritizing (or hide it from priority) without
     * spending an AI call re-scoring it.
     */
    public function toggleFavorite(Request $request, Lead $lead): JsonResponse
    {
        $lead->update(['is_favorite' => ! $lead->is_favorite]);

        ActivityLog::record(ActivityType::LeadStatusUpdated, subject: $lead, meta: [
            'action' => 'favorite_toggled',
            'is_favorite' => $lead->is_favorite,
        ], userId: $request->user()?->id);

        return response()->json(['data' => new LeadResource($lead->fresh('client'))]);
    }

    /**
     * Bulk status change from the leads list's row-checkbox selection. One
     * query, one activity log entry — not a loop of single updateStatus()
     * calls, which would be both slow and enough parallel requests to flirt
     * with the 120/min per-user rate limit on a big selection.
     *
     * Deliberately skips updateStatus()'s "provision a Client record on
     * first forward transition" side effect - that belongs to a single
     * deliberate transition, not a bulk sweep, so a bulk "mark sent" won't
     * silently spawn dozens of Client Memory records.
     */
    public function bulkStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            // Same restriction as the single-lead endpoint: only
            // bidder-driven, forward transitions are settable here.
            'status' => ['required', 'string', Rule::in(['sent', 'replied', 'won', 'archived'])],
        ]);

        $count = Lead::query()->whereIn('id', $validated['ids'])->update(['status' => $validated['status']]);

        ActivityLog::record(ActivityType::LeadStatusUpdated, meta: [
            'action' => 'bulk_status',
            'status' => $validated['status'],
            'count' => $count,
        ], userId: $request->user()?->id);

        return response()->json(['data' => ['message' => "{$count} lead".($count === 1 ? '' : 's')." marked {$validated['status']}.", 'count' => $count]]);
    }

    public function bulkFavorite(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
            'is_favorite' => ['required', 'boolean'],
        ]);

        $count = Lead::query()->whereIn('id', $validated['ids'])->update(['is_favorite' => $validated['is_favorite']]);

        ActivityLog::record(ActivityType::LeadStatusUpdated, meta: [
            'action' => 'bulk_favorite',
            'is_favorite' => $validated['is_favorite'],
            'count' => $count,
        ], userId: $request->user()?->id);

        return response()->json(['data' => ['message' => "{$count} lead".($count === 1 ? '' : 's')." updated.", 'count' => $count]]);
    }

    /**
     * Synchronous re-score — one shared pipeline (LeadRefreshService)
     * with the Agent API; this is just the dashboard's mouth on it.
     */
    public function regenerateScore(Lead $lead, \App\Services\LeadRefreshService $refresh): JsonResponse
    {
        try {
            $refresh->rescore($lead, 'dashboard');
        } catch (\App\Services\LeadRunInProgressException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['data' => new LeadResource($lead->fresh())]);
    }

    /**
     * Synchronous fresh proposal — same shared pipeline as the Agent
     * API's rewrite. The SPA shows a non-blocking progress toast while
     * it runs (~15-60s with the quality gate).
     */
    public function regenerateProposal(Lead $lead, \App\Services\LeadRefreshService $refresh): JsonResponse
    {
        try {
            $refresh->rewrite($lead, 'dashboard');
        } catch (\App\Services\LeadRunInProgressException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\App\Services\Ai\ProposalWritingPausedException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['data' => new LeadResource($lead->fresh())]);
    }

    public function rescore(Lead $lead): JsonResponse
    {
        $lead->update([
            'status' => LeadStatus::New,
            'score' => null,
            'score_reason' => null,
            'proposal_text' => null,
        ]);

        ScoreLeadJob::dispatch($lead->id);

        ActivityLog::record(ActivityType::LeadStatusUpdated, subject: $lead, meta: ['action' => 'rescore_requested']);

        return response()->json(['data' => new LeadResource($lead->fresh())]);
    }

    /**
     * Manual, on-demand mirror of the configured Vollna filter's current
     * results — runs as a background job since it can take a couple of
     * minutes (Vollna's own rate limit). Never triggered automatically.
     */
    public function syncVollna(): JsonResponse
    {
        VollnaSyncJob::dispatch();

        return response()->json(['data' => ['message' => 'Sync started — this can take a couple of minutes.']]);
    }
}
