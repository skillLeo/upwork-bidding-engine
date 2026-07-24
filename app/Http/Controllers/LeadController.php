<?php

namespace App\Http\Controllers;

use App\Enums\ActivityType;
use App\Enums\ClientStage;
use App\Enums\LeadStatus;
use App\Http\Requests\Leads\UpdateLeadStatusRequest;
use App\Http\Resources\LeadResource;
use App\Jobs\ScoreLeadJob;
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

        $sort = (string) $request->query('sort', '-posted_at');

        if (ltrim($sort, '-') === 'priority') {
            // Priority = score minus a continuous age penalty, so a fresh
            // mid-score can outrank a stale high-score (unlike the lexicographic
            // "attention" sort, where score always dominates). The decay rate
            // is operator-tunable. Age is computed DB-side (differs by driver)
            // so ordering stays correct across pages, not just the loaded rows.
            $decay = (float) app(\App\Services\SettingsService::class)->get('priority_decay_rate', 0.05);
            $ageHours = $query->getConnection()->getDriverName() === 'sqlite'
                ? "((julianday('now') - julianday(posted_at)) * 24.0)"
                : 'TIMESTAMPDIFF(SECOND, posted_at, NOW()) / 3600.0';
            $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

            // COALESCE keeps unscored / undated leads from poisoning the sort:
            // no score counts as 0, no posted_at as no age penalty.
            $query->orderByRaw("(COALESCE(score, 0) - COALESCE({$ageHours}, 0) * ?) {$direction}", [$decay])
                ->orderByDesc('score')
                ->orderBy('id', 'desc');
        } elseif (ltrim($sort, '-') === 'attention') {
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

    public function updateStatus(UpdateLeadStatusRequest $request, Lead $lead, \App\Services\ProposalVersionRecorder $versions): JsonResponse
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

            // Freeze the current text as the record of what actually went to
            // the client, so later edits to proposal_text don't rewrite it.
            // Only the first send freezes (markLatestSent no-ops if already
            // sent); a re-send after edits is a deliberate manual re-mark.
            $versions->markLatestSent($lead);
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
     * Records how far the proposal got on the client's side, read off Upwork
     * by hand (there is no API for it). Explicitly nullable: clearing it back
     * to "not recorded" must stay possible, because null is a real state that
     * keeps the lead out of the dying-proposals denominator rather than
     * silently counting as "never opened".
     */
    public function updateClientView(Request $request, Lead $lead): JsonResponse
    {
        $validated = $request->validate([
            'client_view' => ['present', 'nullable', Rule::in(\App\Enums\ClientView::values())],
        ]);

        $lead->update(['client_view' => $validated['client_view']]);

        ActivityLog::record(ActivityType::LeadStatusUpdated, subject: $lead, meta: [
            'action' => 'client_view_set',
            'client_view' => $validated['client_view'],
        ], userId: $request->user()?->id);

        return response()->json(['data' => new LeadResource($lead->fresh('client'))]);
    }

    /**
     * Records why a sent proposal did NOT land. Nothing here overlaps
     * `status` (see LeadOutcome's docblock) - status owns Replied and Won,
     * this owns the four things status cannot say.
     */
    public function updateOutcome(Request $request, Lead $lead): JsonResponse
    {
        $validated = $request->validate([
            'outcome' => ['present', 'nullable', Rule::in(\App\Enums\LeadOutcome::values())],
        ]);

        $lead->update([
            'outcome' => $validated['outcome'],
            'outcome_at' => $validated['outcome'] !== null ? now() : null,
        ]);

        ActivityLog::record(ActivityType::LeadStatusUpdated, subject: $lead, meta: [
            'action' => 'outcome_set',
            'outcome' => $validated['outcome'],
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

        // Mass update, so the Lead model's booted()->updating() stamp never
        // fires (that hook only runs on individual model saves) - stamp
        // submitted_at here directly for the same first-sent-only rule,
        // scoped with whereNull so a lead already sent once keeps its
        // original time even if it's bulk-marked sent again.
        if ($validated['status'] === 'sent') {
            Lead::query()->whereIn('id', $validated['ids'])->whereNull('submitted_at')->update(['submitted_at' => now()]);
        }

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
        } catch (\Throwable $e) {
            return $this->aiFailureResponse($e);
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
        } catch (\Throwable $e) {
            return $this->aiFailureResponse($e);
        }

        return response()->json(['data' => new LeadResource($lead->fresh())]);
    }

    /**
     * Manual, by-hand edit of the current proposal text. Appends a new
     * immutable version (which re-runs the linter) and updates the lead's live
     * proposal_text + warnings so a hand edit can't silently reintroduce a
     * banned claim - the same rule check the AI writer is held to. Available to
     * admin + bidder, same as status updates.
     */
    public function updateProposal(Request $request, Lead $lead, \App\Services\ProposalVersionRecorder $versions): JsonResponse
    {
        $validated = $request->validate([
            'proposal_text' => ['required', 'string', 'max:20000'],
        ]);

        $version = $versions->record($lead, $validated['proposal_text'], 'manual_edit', null, $request->user()?->id);

        $lead->update([
            'proposal_text' => $validated['proposal_text'],
            'proposal_warnings' => $version->linter_violations,
        ]);

        ActivityLog::record('proposal_edited', subject: $lead, meta: [
            'version' => $version->version_number,
            'violations' => $version->linter_violation_count,
        ], userId: $request->user()?->id);

        return response()->json(['data' => new LeadResource($lead->fresh('client'))]);
    }

    /**
     * The lead's proposal history, newest first, for the version timeline.
     */
    public function proposalVersions(Lead $lead): JsonResponse
    {
        return response()->json([
            'data' => \App\Http\Resources\ProposalVersionResource::collection(
                $lead->proposalVersions()->reorder('version_number', 'desc')->get()
            ),
        ]);
    }

    /**
     * AI-assisted edit, PREVIEW only. With a selection range the model edits
     * just that span; without one it revises the whole proposal. The result is
     * linted and returned but NOT persisted - the operator sees the diff and
     * linter delta, then explicitly accepts (acceptAiEditProposal) or discards.
     */
    public function aiEditProposal(Request $request, Lead $lead, \App\Services\Ai\ProposalEditor $editor): JsonResponse
    {
        $validated = $request->validate([
            'instruction' => ['required', 'string', 'max:1000'],
            'selection_start' => ['nullable', 'integer', 'min:0', 'required_with:selection_end'],
            'selection_end' => ['nullable', 'integer', 'min:1', 'gt:selection_start', 'required_with:selection_start'],
        ]);

        if ($this->proposalIsLocked($lead)) {
            return response()->json(['message' => 'This proposal was marked sent and is locked. AI edits are disabled on a sent proposal.'], 422);
        }

        try {
            $preview = $editor->preview(
                $lead,
                $validated['instruction'],
                $validated['selection_start'] ?? null,
                $validated['selection_end'] ?? null,
            );
        } catch (\App\Services\Ai\ProposalEditFailedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return $this->aiFailureResponse($e);
        }

        return response()->json(['data' => $preview]);
    }

    /**
     * Persist a previewed AI edit as a new immutable version. The submitted
     * text is re-linted by the recorder, so the stored warnings are always
     * computed here and can't be spoofed by the client.
     */
    public function acceptAiEditProposal(Request $request, Lead $lead, \App\Services\ProposalVersionRecorder $versions): JsonResponse
    {
        $validated = $request->validate([
            'proposal_text' => ['required', 'string', 'max:20000'],
            'edit_type' => ['required', Rule::in(['ai_surgical_edit', 'ai_instructed_rewrite'])],
            'instruction' => ['nullable', 'string', 'max:1000'],
            'model' => ['nullable', 'string', 'max:64'],
            'selection_start' => ['nullable', 'integer', 'min:0'],
            'selection_end' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($this->proposalIsLocked($lead)) {
            return response()->json(['message' => 'This proposal was marked sent and is locked. AI edits are disabled on a sent proposal.'], 422);
        }

        $version = $versions->record(
            $lead,
            $validated['proposal_text'],
            $validated['edit_type'],
            $validated['model'] ?? null,
            $request->user()?->id,
            [
                'instruction' => $validated['instruction'] ?? null,
                'selection_start' => $validated['selection_start'] ?? null,
                'selection_end' => $validated['selection_end'] ?? null,
            ],
        );

        $lead->update([
            'proposal_text' => $validated['proposal_text'],
            'proposal_warnings' => $version->linter_violations,
        ]);

        ActivityLog::record('proposal_ai_edited', subject: $lead, meta: [
            'version' => $version->version_number,
            'edit_type' => $version->edit_type,
            'violations' => $version->linter_violation_count,
        ], userId: $request->user()?->id);

        return response()->json(['data' => new LeadResource($lead->fresh('client'))]);
    }

    /**
     * A proposal is locked once a version has been frozen as sent - no AI edit
     * may touch it after it has gone to the client.
     */
    protected function proposalIsLocked(Lead $lead): bool
    {
        return $lead->proposalVersions()->where('is_sent', true)->exists();
    }

    /**
     * Providers now retry a 429 internally (see OpenAiProvider/
     * AnthropicProvider) — this only fires once those retries are
     * genuinely exhausted, so it tells the operator something actionable
     * instead of a bare "Server Error".
     */
    protected function aiFailureResponse(\Throwable $e): JsonResponse
    {
        report($e);

        if ($e instanceof \Illuminate\Http\Client\RequestException && $e->response->status() === 429) {
            return response()->json([
                'message' => 'The AI provider is rate-limited right now. Wait a minute and try again.',
            ], 503);
        }

        return response()->json([
            'message' => 'The run failed or timed out — check the dashboard for the current state.',
        ], 500);
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
     * Manual "Sync now" — runs the SAME additive poll the scheduler runs every
     * minute (vollna:poll-api), but INLINE so the operator gets an immediate,
     * honest result: a count of what was imported, or the real failure reason.
     *
     * This deliberately replaced the old async VollnaSyncJob dispatch, which
     * (a) still sent Vollna's rejected `limit` param, (b) competed with the
     * every-minute poller for Vollna's ~5-req/min limit and 429'd, and (c)
     * reported failure only to a hidden setting, so the button looked dead.
     */
    public function syncVollna(): JsonResponse
    {
        @set_time_limit(60);

        $exit = \Illuminate\Support\Facades\Artisan::call('vollna:poll-api');
        $output = trim(\Illuminate\Support\Facades\Artisan::output());

        if ($exit !== 0) {
            // Surface the actual reason (rate limit, auth, network) - never a
            // silent failure. HTTP 429 from Vollna is common right after the
            // auto-poller just ran; the operator gets told to retry shortly.
            $reason = str_contains($output, 'failed:')
                ? trim(mb_substr($output, mb_strpos($output, 'failed:') + 7))
                : 'The Vollna poll did not complete. Try again in a moment.';

            if (str_contains($output, 'HTTP 429')) {
                $reason = 'Vollna is rate-limiting right now (the auto-poller just ran). New leads still arrive automatically every minute — try Sync again in a minute.';
            }

            return response()->json(['message' => $reason], 503);
        }

        preg_match('/(\d+) new/', $output, $matches);
        $new = (int) ($matches[1] ?? 0);

        return response()->json(['data' => [
            'imported' => $new,
            'message' => $new > 0
                ? "{$new} new lead".($new === 1 ? '' : 's')." imported."
                : "No new leads — you're already up to date.",
        ]]);
    }
}
