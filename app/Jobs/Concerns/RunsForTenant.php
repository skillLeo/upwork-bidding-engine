<?php

namespace App\Jobs\Concerns;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;

/**
 * THE classic multi-tenancy bug, closed.
 *
 * Global scopes work inside a web request because the middleware bound a
 * tenant. A queued job runs with no request and therefore no tenant, which
 * means one of two things: an exception (annoying) or, if the scope were
 * lenient, a silent cross-tenant write (catastrophic and invisible).
 *
 * Every job captures the tenant id AT DISPATCH TIME and rebinds it before
 * doing anything else. Capturing at dispatch rather than looking it up in
 * handle() matters: by the time the queue drains, the request that dispatched
 * the job is long gone.
 */
trait RunsForTenant
{
    /**
     * Serialised with the job payload. Public so Laravel's serializer keeps
     * it; nullable only so a job queued before this shipped does not fatal
     * on unserialize — those are handled explicitly in forTenant().
     */
    public ?int $tenantId = null;

    /**
     * Call from every job constructor.
     */
    protected function captureTenant(): void
    {
        $this->tenantId = app(TenantContext::class)->id();
    }

    /**
     * Wrap the whole body of handle() in this.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $fn
     * @return TReturn|null
     */
    protected function forTenant(Closure $fn): mixed
    {
        $context = app(TenantContext::class);

        // Already bound and matching (a sync/inline dispatch inside a
        // request) — just run, no redundant lookup.
        if ($this->tenantId !== null && $context->id() === $this->tenantId) {
            return $fn();
        }

        if ($this->tenantId === null) {
            // A job serialised before tenancy shipped, or dispatched from
            // unbound code. Refusing is the honest answer: guessing an owner
            // is how data ends up in the wrong workspace.
            report(new \RuntimeException(
                static::class.' ran with no tenant_id in its payload and was skipped. '
                .'This is a job queued before tenancy shipped, or dispatched from code '
                .'that had no tenant bound.'
            ));

            return null;
        }

        // TENANCY: resolving the tenant to run AS. Reads the tenants table,
        // which is never tenant-scoped.
        $tenant = \App\Tenancy\Tenancy::asPlatform(fn () => Tenant::find($this->tenantId));

        if ($tenant === null) {
            report(new \RuntimeException(
                static::class." references tenant {$this->tenantId}, which no longer exists. Skipped."
            ));

            return null;
        }

        return $context->runAs($tenant, $fn);
    }
}
