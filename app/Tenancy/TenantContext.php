<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Closure;

/**
 * The single source of truth for "whose data is this request touching".
 *
 * Registered as a container singleton. The global scope in BelongsToTenant
 * reads from HERE and nowhere else — never from a request parameter, header
 * or route argument, because a user-supplied tenant id is a horizontal
 * privilege escalation waiting to happen.
 */
class TenantContext
{
    protected ?Tenant $tenant = null;

    /**
     * When set, the tenant scope is skipped entirely. Deliberately a counter
     * rather than a bool so nested asPlatform() calls don't have the inner
     * one switch scoping back on for the outer.
     */
    protected int $platformDepth = 0;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function forget(): void
    {
        $this->tenant = null;
    }

    public function isPlatform(): bool
    {
        return $this->platformDepth > 0;
    }

    /**
     * Run $fn as a different tenant, restoring the previous one afterwards
     * even if $fn throws. Used by queued jobs and console commands, which
     * have no middleware to set the context for them.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $fn
     * @return TReturn
     */
    public function runAs(Tenant $tenant, Closure $fn): mixed
    {
        $previous = $this->tenant;
        $previousDepth = $this->platformDepth;

        $this->tenant = $tenant;

        // PLATFORM MODE IS SUSPENDED FOR THE DURATION, and this line is the
        // whole reason this method is not three lines long.
        //
        // "Run as this tenant" has to mean the tenant scope is ON. Without
        // this, asPlatform(fn () => runAs($t, ...)) left the scope OFF while
        // reading exactly like scoped code: every query inside saw every
        // workspace's rows. It is the precise failure BelongsToTenant exists
        // to make impossible, wearing the costume of the fix.
        //
        // Found the hard way. LeadFanOut reads the shared pool as platform
        // and then delivers each item to one workspace at a time; its
        // "does this workspace already have this job?" check was answered
        // across ALL workspaces, so a second workspace was told it already
        // held all 38 leads it had never seen and received nothing. A silent
        // no-op, in the one code path whose entire job is giving workspaces
        // their leads.
        //
        // Nesting still works both ways: asPlatform() inside runAs() is an
        // explicit, greppable opt-out, and restoring the depth here means
        // the outer platform block continues unaffected afterwards.
        $this->platformDepth = 0;

        try {
            return $fn();
        } finally {
            $this->tenant = $previous;
            $this->platformDepth = $previousDepth;
        }
    }

    /**
     * Run $fn with the tenant scope OFF — for work that legitimately spans
     * tenants (the data migration, platform admin, cross-tenant totals).
     *
     * Every cross-tenant query in the app must go through here and nothing
     * else, so that `grep asPlatform` is a complete list of the places
     * isolation is intentionally bypassed.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $fn
     * @return TReturn
     */
    public function asPlatform(Closure $fn): mixed
    {
        $this->platformDepth++;

        try {
            return $fn();
        } finally {
            $this->platformDepth--;
        }
    }
}
