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
        $this->tenant = $tenant;

        try {
            return $fn();
        } finally {
            $this->tenant = $previous;
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
