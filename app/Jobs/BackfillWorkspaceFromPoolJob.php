<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Leads\LeadFanOut;
use App\Tenancy\Tenancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fill a workspace's board from the shared pool.
 *
 * Runs when a workspace is created, so its owner's first sign-in shows a
 * working board rather than an empty one, and on demand from the platform
 * console when a workspace needs catching up.
 *
 * DELIBERATELY NOT RunsForTenant. That trait captures whichever workspace was
 * bound at dispatch time, which here is the platform owner's — the wrong
 * answer entirely. This job is *about* a workspace rather than *for* one, so
 * it carries the target id explicitly and LeadFanOut binds it per delivery.
 */
class BackfillWorkspaceFromPoolJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * One try. A backfill is not idempotent in cost — every delivery
     * dispatches a scoring job — and LeadFanOut already skips anything the
     * workspace has. A retry would mostly re-walk the pool to deliver
     * nothing, so a genuine failure is better read in the log than papered
     * over by three attempts.
     */
    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public int $tenantId,
        public int $days = 7,
    ) {}

    public function handle(LeadFanOut $fanOut): void
    {
        // TENANCY: resolving the target workspace. The tenants table is
        // never tenant-scoped.
        $tenant = Tenancy::asPlatform(fn () => Tenant::find($this->tenantId));

        if ($tenant === null) {
            // Created and deleted before the queue drained. Nothing to say.
            return;
        }

        $fanOut->backfillWorkspace($tenant, $this->days);
    }
}
