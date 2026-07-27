<?php

namespace App\Services\Leads;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Jobs\ScoreLeadJob;
use App\Models\ActivityLog;
use App\Models\DeletedLeadExternalId;
use App\Models\Lead;
use App\Models\LeadSourceItem;
use App\Models\Tenant;
use App\Tenancy\Tenancy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * Hands one pool item to every workspace entitled to it.
 *
 * The product sells access to ONE lead feed. Every workspace sees the same
 * jobs; what differs is the four things each owner controls — their filters,
 * their stacks (which drive scoring), their stacks again (which drive the
 * proposal), and their bidding rules. So distribution is deliberately dumb:
 * it does not decide whether a job "suits" a workspace, because that
 * judgement belongs to the workspace's own rules downstream, where its owner
 * can see it and change it.
 *
 * WHY A COPY PER WORKSPACE, rather than one shared row with a per-workspace
 * overlay table. Half of `leads` is opinion — score, status, favourite,
 * proposal, outcome — and score and status are the Leads page's sort and
 * filter keys. Moving them to an overlay would turn nearly every query in
 * the app into a join: 21 files call Lead::, AnalyticsService alone has 13
 * call sites, and 29 files read those columns directly. Copying leaves all
 * of them untouched, because "my workspace's leads" stays literally true.
 * The cost is the job's text stored once per workspace, which at a few
 * hundred leads a day is nothing.
 *
 * Scoring is always QUEUED here, never inline. The old webhook path scored
 * synchronously so an alert landed in seconds, which worked when one
 * workspace meant one 60-90s scoring run. N workspaces means N of them, and
 * no HTTP request or scheduler tick survives that.
 */
class LeadFanOut
{
    /**
     * Offer one pool item to every eligible workspace.
     *
     * @param  Collection<int, Tenant>|null  $tenants  defaults to every eligible workspace
     * @return array{delivered: int, skipped: int, tenants: array<int, string>}
     */
    public function distribute(LeadSourceItem $item, ?Collection $tenants = null): array
    {
        $tenants ??= $this->eligibleWorkspaces();

        $delivered = 0;
        $skipped = 0;
        $reached = [];

        foreach ($tenants as $tenant) {
            // One workspace's broken state must never stop the others being
            // fed — this loop is the single intake path for the whole
            // platform, and it runs inside one shared cron tick.
            try {
                if ($this->deliver($item, $tenant)) {
                    $delivered++;
                    $reached[] = $tenant->slug;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                report($e);
                $skipped++;
            }
        }

        $item->forceFill(['fanned_out_at' => now()])->save();

        return ['delivered' => $delivered, 'skipped' => $skipped, 'tenants' => $reached];
    }

    /**
     * Project one item into one workspace. Returns false when the workspace
     * already has it or has permanently deleted it.
     */
    public function deliver(LeadSourceItem $item, Tenant $tenant): bool
    {
        return Tenancy::runAs($tenant, function () use ($item) {
            // Tenant-scoped on purpose: one workspace permanently deleting a
            // job must not withhold it from anybody else. This is the
            // resurrection guard that stopped a mirror sync bringing back
            // 515 deleted leads.
            if (DeletedLeadExternalId::where('external_id', $item->external_id)->exists()) {
                return false;
            }

            if (Lead::where('external_id', $item->external_id)->exists()) {
                return false;
            }

            try {
                $lead = Lead::create([
                    ...$item->projection(),
                    'status' => LeadStatus::New,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Two intake paths raced (the poller and a manual sync) and
                // both passed the exists() check. The unique on
                // (tenant_id, external_id) is the real arbiter; losing the
                // race is a duplicate, not an error.
                return false;
            }

            ActivityLog::record(ActivityType::LeadReceived, subject: $lead, meta: [
                'source' => $item->source,
                'source_item_id' => $item->id,
            ]);

            // Dispatched inside the tenant binding so RunsForTenant captures
            // THIS workspace — a job queued outside it would score the lead
            // against whichever workspace happened to be bound.
            ScoreLeadJob::dispatch($lead->id);

            return true;
        });
    }

    /**
     * Fill a workspace from the pool — the day-one board for a newly created
     * workspace, which would otherwise sit empty until the next poll and
     * look broken.
     *
     * Bounded by age, not by count: a job older than the window is dead
     * inventory nobody can win, and scoring it costs real money per
     * workspace.
     *
     * @return array{delivered: int, skipped: int}
     */
    public function backfillWorkspace(Tenant $tenant, int $days = 7): array
    {
        $delivered = 0;
        $skipped = 0;

        // TENANCY: the pool is not tenant-owned; reading it is the point of
        // this method. The writes below are scoped by deliver().
        Tenancy::asPlatform(function () use ($tenant, $days, &$delivered, &$skipped) {
            LeadSourceItem::query()
                ->where('posted_at', '>=', now()->subDays($days))
                ->orderByDesc('posted_at')
                ->chunkById(200, function ($items) use ($tenant, &$delivered, &$skipped) {
                    foreach ($items as $item) {
                        try {
                            $this->deliver($item, $tenant) ? $delivered++ : $skipped++;
                        } catch (\Throwable $e) {
                            report($e);
                            $skipped++;
                        }
                    }
                });
        });

        // Bound explicitly: activity_logs is tenant-owned, and the callers
        // here (the platform console, a queued job) legitimately have no
        // workspace of their own bound.
        Tenancy::runAs($tenant, fn () => ActivityLog::record('workspace_backfilled_from_pool', meta: [
            'tenant_id' => $tenant->id,
            'days' => $days,
            'delivered' => $delivered,
            'skipped' => $skipped,
        ]));

        return ['delivered' => $delivered, 'skipped' => $skipped];
    }

    /**
     * Every workspace that should receive leads right now.
     *
     * isBillingBlocked() excludes suspended AND past_due, matching what the
     * poller already refused to spend AI credit on — distribution is the
     * step that CAUSES that spend, so the gate belongs here too.
     *
     * @return Collection<int, Tenant>
     */
    public function eligibleWorkspaces(): Collection
    {
        // TENANCY: the tenants table is never tenant-scoped — it is the
        // table the scope is derived from.
        return Tenancy::asPlatform(fn () => Tenant::query()
            ->orderBy('id')
            ->get()
            ->reject(fn (Tenant $t) => $t->isBillingBlocked())
            ->values());
    }
}
