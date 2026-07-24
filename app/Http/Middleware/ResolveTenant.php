<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the tenant for the duration of a web request, from the HOST ONLY.
 *
 * Never from a query string, header or route parameter. A user-supplied
 * tenant id is horizontal privilege escalation, and the global scope reads
 * from TenantContext precisely so there is one place to audit.
 *
 * Resolution order:
 *   1. First label of the host matches a tenant slug  -> that tenant.
 *   2. Otherwise the configured fallback tenant       -> that tenant.
 *   3. Otherwise 404.
 *
 * Step 2 is why this ships without taking production down. The live host is
 * upwork.skillleo.com, whose first label is "upwork" — not a tenant slug.
 * Strict subdomain resolution alone would 404 every request the moment this
 * deploys. Set tenancy.strict_subdomain to true (and give each tenant its own
 * subdomain) to remove the fallback when real customers exist.
 */
class ResolveTenant
{
    public function __invoke(Request $request, Closure $next): Response
    {
        return $this->handle($request, $next);
    }

    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);

        $tenant = $this->fromHost($request->getHost()) ?? $this->fallback();

        if ($tenant === null) {
            abort(404, 'Workspace not found.');
        }

        if ($tenant->isSuspended()) {
            abort(403, 'This workspace is suspended.');
        }

        // Restore rather than forget. Blindly clearing would unbind a tenant
        // that surrounding code had deliberately set — which is exactly what
        // happens in a test that binds a tenant and then makes an HTTP call,
        // and would happen in any nested/inline dispatch too.
        $previous = $context->get();
        $context->set($tenant);

        try {
            return $next($request);
        } finally {
            $context->set($previous);
        }
    }

    protected function fromHost(string $host): ?Tenant
    {
        if (in_array($host, (array) config('tenancy.central_domains', []), true)) {
            return null;
        }

        $labels = explode('.', $host);

        // Needs at least sub.domain.tld before the first label can be a slug.
        if (count($labels) < 3) {
            return null;
        }

        // TENANCY: reads the tenants table, which is not tenant-scoped — it
        // is the table the scope is derived from.
        return Tenant::where('slug', strtolower($labels[0]))->first();
    }

    protected function fallback(): ?Tenant
    {
        if (config('tenancy.strict_subdomain', false)) {
            return null;
        }

        $slug = config('tenancy.default_tenant_slug');

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        // TENANCY: same as above — the tenants table is never tenant-scoped.
        return Tenant::where('slug', $slug)->first();
    }
}
