<?php

namespace App\Services;

use App\Enums\ActivityType;
use App\Models\ActivityLog;
use App\Models\DeletedLeadExternalId;
use App\Models\Lead;
use App\Models\LeadSourceItem;
use App\Services\Leads\LeadFanOut;
use App\Tenancy\Tenancy;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Turns a raw Vollna project into a pool item, and the pool item into a lead
 * on every workspace's board.
 *
 * Two doors, deliberately not one:
 *
 *   ingest()        platform-wide. The scheduled poller and the webhook.
 *                   One job in, every eligible workspace served.
 *   importProject() this workspace only. The manual "Sync now" button and
 *                   the one-off backfill command.
 *
 * Both share the field mapping, the age gate and the pool, so the two paths
 * can never disagree about what a job is or whether it is too old — only
 * about who gets it.
 */
class VollnaProjectImporter
{
    public function __construct(
        protected SettingsService $settings,
        protected LeadFanOut $fanOut,
    ) {}


    /**
     * The REST API returns a differently-shaped project than the webhook
     * batch does (camelCase, budget as {type, amount}, publishedAt) -
     * translate it into the webhook's shape so importProject()'s mapping
     * logic handles both without duplicating it. Shared by vollna:backfill
     * and VollnaSyncJob, the two REST-API-driven callers.
     *
     * @param  array<string, mixed>  $project
     * @return array<string, mixed>
     */
    public function normalizeApiProject(array $project): array
    {
        $client = $project['clientDetails'] ?? [];
        $budget = $project['budget'] ?? [];
        $budgetType = strtolower((string) ($budget['type'] ?? ''));

        return [
            'title' => $project['title'] ?? null,
            'description' => $project['description'] ?? null,
            'skills' => $project['skills'] ?? $project['tags'] ?? null,
            'url' => $project['url'] ?? null,
            'published' => $project['publishedAt'] ?? null,
            'budget' => $budget['amount'] ?? null,
            'budget_type' => str_contains($budgetType, 'hour') ? 'hourly' : 'fixed',
            'connects_required' => $project['connectsRequired'] ?? $project['connects'] ?? null,
            'client_details' => [
                'country' => $client['country'] ?? null,
                'total_spent' => $client['totalSpent'] ?? null,
                'hire_rate' => $client['hireRate'] ?? null,
                'payment_method_verified' => $client['paymentMethodVerified'] ?? null,
                'rating' => $client['rating'] ?? null,
                'reviews' => $client['reviews'] ?? null,
            ],
        ];
    }

    /**
     * THE PLATFORM INTAKE DOOR: one job into the shared pool, then out to
     * every workspace entitled to it.
     *
     * This is what the scheduled poller and the Vollna webhook call. It runs
     * ONCE per delivery, not once per workspace — the old arrangement had
     * every workspace poll Vollna separately and import into itself, which
     * multiplied the API bill by the number of customers (measured live:
     * 1,441 calls/day at one workspace, 3,944 at four) and could never work
     * anyway, because leads.external_id was globally unique.
     *
     * @param  array<string, mixed>  $project
     * @return array<string, mixed>
     */
    public function ingest(array $project): array
    {
        $mapped = $this->mapPayload($project);

        if (($gate = $this->gate($mapped)) !== null) {
            return $gate;
        }

        [$item, $isNew] = $this->resolveSourceItem($mapped);

        if (! $isNew) {
            // Idempotent: Vollna re-delivers, and the poller sees the same
            // newest page every minute. A job already in the pool must not
            // be re-distributed or re-scored anywhere.
            return ['status' => 'duplicate', 'source_item_id' => $item->id];
        }

        $fan = $this->fanOut->distribute($item);

        ActivityLog::record(ActivityType::LeadReceived, meta: [
            'source' => 'vollna',
            'source_item_id' => $item->id,
            'external_id' => $item->external_id,
            'delivered_to' => $fan['tenants'],
        ]);

        return [
            'status' => 'accepted',
            'source_item_id' => $item->id,
            'delivered' => $fan['delivered'],
            'skipped' => $fan['skipped'],
        ];
    }

    /**
     * ONE WORKSPACE ONLY — the manual "Sync now" button and the one-off
     * backfill command, both of which are a person asking for THEIR
     * workspace to be brought up to date.
     *
     * Still routes through the pool, so a job pulled in this way is
     * available to every other workspace afterwards rather than being
     * privately owned by whoever pressed the button.
     *
     * @param  array<string, mixed>  $project
     * @return array<string, mixed>
     */
    public function importProject(array $project): array
    {
        $tenant = Tenancy::current();

        if ($tenant === null) {
            throw new \RuntimeException(
                'importProject() delivers to the CURRENT workspace and none is bound. '
                .'Platform-wide intake goes through ingest().'
            );
        }

        $mapped = $this->mapPayload($project);

        if (($gate = $this->gate($mapped)) !== null) {
            return $gate;
        }

        // Checked before anything else: a permanently deleted lead has no
        // live row to match against, but must still never come back. See the
        // deleted_lead_external_ids migration for the incident this closes
        // (a mirror sync resurrected 515 deleted leads). Tenant-scoped — one
        // workspace deleting a job says nothing about anyone else's.
        if (DeletedLeadExternalId::where('external_id', $mapped['external_id'])->exists()) {
            ActivityLog::record(ActivityType::LeadResurrectionBlocked, meta: [
                'external_id' => $mapped['external_id'],
            ]);

            return ['status' => 'deleted_permanently'];
        }

        $existing = Lead::query()->where('external_id', $mapped['external_id'])->first();

        if ($existing) {
            ActivityLog::record(ActivityType::LeadDuplicateSkipped, subject: $existing, meta: [
                'external_id' => $mapped['external_id'],
            ]);

            return ['status' => 'duplicate', 'lead_id' => $existing->id];
        }

        [$item] = $this->resolveSourceItem($mapped);

        if (! $this->fanOut->deliver($item, $tenant)) {
            return ['status' => 'duplicate', 'source_item_id' => $item->id];
        }

        return ['status' => 'accepted', 'source_item_id' => $item->id];
    }

    /**
     * The two refusals that apply before a job is worth storing at all.
     *
     * @param  array<string, mixed>  $mapped
     * @return array<string, mixed>|null  null when the job may proceed
     */
    protected function gate(array $mapped): ?array
    {
        if ($mapped['external_id'] === '' || $mapped['title'] === '') {
            return ['status' => 'skipped', 'reason' => 'missing job identifier or title'];
        }

        // Age gate at the door: a posting older than the scoring window is
        // never even stored. Learned live on 2026-07-19, when a mirror sync
        // resurrected 515 deleted two-week-old leads and dispatched scoring
        // for all of them. Old jobs are dead inventory — no row, no score,
        // no alert. (0 disables the gate.)
        //
        // Read from whichever workspace is bound, which for the poller is
        // the platform's own. Each workspace ALSO enforces its own
        // max_posted_age_days in applyHardFilters, so a workspace wanting a
        // tighter window still gets one; this is only the outer bound on
        // what enters the pool.
        $maxAgeDays = (int) $this->settings->get('max_posted_age_days', 7);

        if ($maxAgeDays > 0 && $mapped['posted_at'] !== null && $mapped['posted_at']->lt(now()->subDays($maxAgeDays))) {
            return ['status' => 'skipped', 'reason' => "posted more than {$maxAgeDays} days ago — not imported"];
        }

        return null;
    }

    /**
     * Find or create the pool row for this job.
     *
     * proposal_count is refreshed on an item already in the pool: it is the
     * one fact that genuinely moves after publication, and a stale count
     * feeds every workspace's max_proposals rule the wrong number. Nothing
     * else is overwritten — re-editing a job's title or budget after the
     * fact is not something the source reports reliably, and rewriting the
     * text under a proposal a workspace has already drafted against it would
     * be worse than being slightly out of date.
     *
     * @param  array<string, mixed>  $mapped
     * @return array{0: LeadSourceItem, 1: bool}  the item, and whether it is new
     */
    protected function resolveSourceItem(array $mapped): array
    {
        // TENANCY: the pool is owned by nobody by design — see
        // LeadSourceItem. This is the write that fills it.
        return Tenancy::asPlatform(function () use ($mapped) {
            $facts = array_intersect_key(
                $mapped,
                array_flip(LeadSourceItem::PROJECTED_COLUMNS),
            );

            $existing = LeadSourceItem::where('external_id', $mapped['external_id'])->first();

            if ($existing !== null) {
                if ((int) $existing->proposal_count !== (int) ($facts['proposal_count'] ?? 0)) {
                    $existing->update(['proposal_count' => $facts['proposal_count'] ?? 0]);
                }

                return [$existing, false];
            }

            return [LeadSourceItem::create([...$facts, 'source' => 'vollna']), true];
        });
    }

    /**
     * Vollna's exact field names aren't a contract we control, so this maps
     * defensively across a few plausible aliases instead of trusting one
     * exact shape. The primary shape is a project entry from Vollna's "new
     * job" webhook batch (client_details, budget_type + a formatted budget
     * string, published) - older/alternate field names are kept as
     * fallbacks in case a different delivery type is ever pointed here.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function mapPayload(array $payload): array
    {
        $client = (array) (Arr::get($payload, 'client_details') ?? Arr::get($payload, 'client') ?? []);

        $url = Arr::get($payload, 'url') ?? Arr::get($payload, 'link');

        $externalId = Arr::get($payload, 'external_id')
            ?? Arr::get($payload, 'id')
            ?? Arr::get($payload, 'job_id')
            ?? Arr::get($payload, 'uid')
            ?? $this->extractProjectId($url);

        $title = trim((string) Arr::get($payload, 'title', ''));

        if (! $externalId && $title !== '') {
            // Last-resort stable id so a malformed-but-real payload still dedupes on retry.
            $externalId = 'vollna_'.md5($title.'|'.$url);
        }

        return [
            'external_id' => (string) ($externalId ?? ''),
            'title' => $title,
            'full_brief' => (string) (
                Arr::get($payload, 'description')
                ?? Arr::get($payload, 'brief')
                ?? Arr::get($payload, 'full_brief')
                ?? ''
            ),
            // Field name/shape isn't confirmed against a real Vollna payload
            // yet (no docs access) - defensive aliases like connects_required
            // below, but verify against one live webhook delivery and adjust
            // if none of these actually match.
            'skills' => $this->normalizeSkills(
                Arr::get($payload, 'skills')
                    ?? Arr::get($payload, 'tags')
                    ?? Arr::get($payload, 'category_skills')
                    ?? Arr::get($payload, 'requiredSkills')
            ),
            'url' => $url,
            'budget' => $budget = $this->normalizeBudget($payload),
            ...$this->parseBudgetRange($budget),
            'budget_type' => $this->resolveBudgetType($payload),
            'client_country' => Arr::get($client, 'country.name')
                ?? Arr::get($client, 'country')
                ?? Arr::get($payload, 'client_country'),
            'client_spend' => $this->stringifyMoney($rawSpend = (
                Arr::get($client, 'total_spent') ?? Arr::get($client, 'totalSpent') ?? Arr::get($payload, 'client_spend')
            )),
            'client_spend_amount' => is_numeric($rawSpend) ? (float) $rawSpend : null,
            'client_hire_rate' => $this->stringifyPercent($rawHireRate = (
                Arr::get($client, 'hire_rate') ?? Arr::get($client, 'hireRate') ?? Arr::get($payload, 'client_hire_rate')
            )),
            'client_hire_rate_pct' => $this->parsePercentNumeric($rawHireRate),
            'client_rating' => Arr::get($client, 'rating') ?? Arr::get($payload, 'client_rating'),
            'client_reviews' => Arr::get($client, 'reviews') ?? Arr::get($payload, 'client_reviews'),
            'payment_verified' => (bool) (
                Arr::get($client, 'payment_method_verified')
                ?? Arr::get($client, 'paymentVerified')
                ?? Arr::get($client, 'payment_verified')
                ?? Arr::get($payload, 'payment_verified')
                ?? false
            ),
            'proposal_count' => (int) (
                Arr::get($payload, 'proposals') ?? Arr::get($payload, 'proposal_count') ?? Arr::get($payload, 'applicants') ?? 0
            ),
            // Field name isn't confirmed against a real Vollna payload yet
            // (no docs access) - defensive aliases like the rest of this
            // map, but verify against one live webhook delivery and adjust
            // if none of these actually match.
            'connects_required' => $this->nullableInt(
                Arr::get($payload, 'connects_required')
                    ?? Arr::get($payload, 'connectsRequired')
                    ?? Arr::get($payload, 'connects')
                    ?? Arr::get($payload, 'required_connects')
                    ?? Arr::get($payload, 'connectPrice')
            ),
            'posted_at' => $this->parseDate(
                Arr::get($payload, 'published') ?? Arr::get($payload, 'postedOn') ?? Arr::get($payload, 'posted_at') ?? Arr::get($payload, 'publishedDateTime')
            ),
        ];
    }

    /**
     * Vollna's "new job" projects carry no explicit id - only a
     * tracking/redirect URL through vollna.com/go with the real project id
     * in its `pid` query parameter. Pull that out so real duplicate
     * deliveries actually dedupe instead of falling through to the
     * title+url hash every time.
     */
    protected function extractProjectId(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $query = parse_url($url, PHP_URL_QUERY);

        if (! $query) {
            return null;
        }

        parse_str($query, $params);

        return isset($params['pid']) && $params['pid'] !== '' ? 'vollna_pid_'.$params['pid'] : null;
    }

    protected function normalizeBudget(array $payload): ?string
    {
        $budget = Arr::get($payload, 'budget');
        $budgetType = Arr::get($payload, 'budget_type');

        // Vollna's "new job" shape: budget is already a formatted string
        // like "15 - 25 USD" or "500 USD", paired with a separate type.
        if (is_string($budget) && $budget !== '') {
            return $budgetType === 'hourly' ? $budget.'/hr' : $budget;
        }

        if (is_numeric($budget)) {
            $amount = '$'.number_format((float) $budget, 0);

            return $budgetType === 'hourly' ? $amount.'/hr' : $amount.' fixed';
        }

        if (is_array($budget)) {
            $type = Arr::get($budget, 'type');

            if (isset($budget['amount'])) {
                $amount = '$'.number_format((float) $budget['amount'], 0);

                return $type === 'hourly' ? $amount.'/hr' : $amount.' fixed';
            }

            $min = Arr::get($budget, 'minimum');
            $max = Arr::get($budget, 'maximum');

            if ($min || $max) {
                $range = ($min && $max) ? '$'.$min.'-$'.$max : '$'.($min ?? $max);

                return $range.'/hr';
            }
        }

        return null;
    }

    /**
     * Mirrors normalizeBudget()'s branches so the discrete fixed/hourly
     * signal Vollna actually sends never disagrees with the display string
     * built from the same payload - previously this was thrown away once
     * baked into `budget` as a "/hr" suffix.
     */
    protected function resolveBudgetType(array $payload): ?string
    {
        $budget = Arr::get($payload, 'budget');
        $budgetType = Arr::get($payload, 'budget_type');

        if (is_string($budgetType) && $budgetType !== '') {
            return strtolower($budgetType) === 'hourly' ? 'hourly' : 'fixed';
        }

        if (is_array($budget) && isset($budget['type'])) {
            return strtolower((string) $budget['type']) === 'hourly' ? 'hourly' : 'fixed';
        }

        if (is_array($budget) && (Arr::get($budget, 'minimum') || Arr::get($budget, 'maximum'))) {
            // normalizeBudget() treats a min/max range as hourly with no
            // separate type field available - same assumption here.
            return 'hourly';
        }

        return null;
    }

    /**
     * `budget` is kept as a free-text display string (e.g. "15 - 25 USD/hr",
     * "$5,000 fixed") since Vollna's own formatting varies by source shape -
     * this pulls the numbers back out so budget range filters can query
     * numerically instead of doing fragile string matching.
     *
     * @return array{budget_min: ?float, budget_max: ?float}
     */
    protected function parseBudgetRange(?string $budget): array
    {
        if (! $budget) {
            return ['budget_min' => null, 'budget_max' => null];
        }

        preg_match_all('/[\d,]+(?:\.\d+)?/', $budget, $matches);
        $numbers = array_map(fn ($n) => (float) str_replace(',', '', $n), $matches[0] ?? []);

        if ($numbers === []) {
            return ['budget_min' => null, 'budget_max' => null];
        }

        return [
            'budget_min' => min($numbers),
            'budget_max' => max($numbers),
        ];
    }

    protected function stringifyMoney(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $amount = (float) $value;

            return $amount >= 1000
                ? '$'.number_format($amount / 1000, 1).'K'
                : '$'.number_format($amount, 0);
        }

        return (string) $value;
    }

    protected function stringifyPercent(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && Str::contains($value, '%')) {
            return $value;
        }

        if (is_numeric($value)) {
            $number = (float) $value;
            $percent = $number <= 1 ? $number * 100 : $number;

            return round($percent).'%';
        }

        return (string) $value;
    }

    /**
     * Same fraction-vs-percent ambiguity as stringifyPercent() (a bare `1`
     * could mean "1%" or "100%") - kept consistent with that method's
     * existing assumption rather than introducing a second interpretation.
     */
    protected function parsePercentNumeric(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            if (! preg_match('/[\d.]+/', $value, $m)) {
                return null;
            }
            $value = (float) $m[0];
        }

        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return round($number <= 1 ? $number * 100 : $number, 1);
    }

    protected function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Skills can arrive as a plain string array or, depending on the API
     * shape, an array of {name}/{skill}/{title} objects - flatten either
     * into plain strings so the frontend never has to care which one it got.
     *
     * @return array<int, string>
     */
    protected function normalizeSkills(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($item) {
            if (is_string($item)) {
                return trim($item);
            }

            if (is_array($item)) {
                return trim((string) ($item['name'] ?? $item['skill'] ?? $item['title'] ?? ''));
            }

            return null;
        }, $value)));
    }

    protected function parseDate(mixed $value): ?Carbon
    {
        if (! $value) {
            return now();
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return now();
        }
    }
}
