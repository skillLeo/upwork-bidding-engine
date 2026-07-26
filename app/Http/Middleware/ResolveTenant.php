<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the tenant for the duration of a web request.
 *
 * Resolution order:
 *   1. First label of the host matches a tenant slug -> that tenant.
 *   2. The tenant the caller's ACCESS TOKEN was issued for -> that tenant.
 *   3. Otherwise the configured fallback tenant.
 *   4. Otherwise 404.
 *
 * STEP 2 EXISTS BECAUSE STEP 1 CANNOT WORK ON THIS DEPLOYMENT, and leaving it
 * out silently broke every workspace but the first. The live host is
 * upwork.skillleo.com, whose first label is "upwork" — not a tenant slug — so
 * host resolution always misses and every request fell through to the
 * fallback, workspace 1. A brand-new workspace owner signing in therefore
 * landed in the FOUNDER'S workspace, where they hold no role at all: no
 * permissions, no navigation, no Settings, and the founder's saved filters
 * on screen. Subdomain switching assumes wildcard DNS that does not exist
 * here, so "give each tenant its own subdomain" was never going to arrive on
 * its own.
 *
 * A TOKEN'S tenant_id IS NOT USER-SUPPLIED. It is written by TokenIssuer at
 * sign-in from the tenant that was bound then, it is stored server-side, and
 * it is reached only by presenting the token's own secret. That is a
 * different thing entirely from reading a tenant id out of a query string,
 * header or route parameter — those remain forbidden, and for the original
 * reason: they would be horizontal privilege escalation.
 *
 * Host still wins when it names a real tenant, so per-subdomain access keeps
 * working wherever the DNS does exist.
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

        $tenant = $this->fromHost($request->getHost())
            ?? $this->fromAccessToken($request)
            ?? $this->fallback();

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

    /**
     * The workspace this token was issued for.
     *
     * Sanctum's findToken() verifies the token's hash before returning
     * anything, so a forged or guessed value resolves to nothing. Expiry and
     * revocation are deliberately NOT re-checked here: auth:sanctum rejects
     * such a request moments later regardless, and a tenant bound for a
     * request that then 401s changes nothing.
     */
    protected function fromAccessToken(Request $request): ?Tenant
    {
        $bearer = $request->bearerToken();

        if ($bearer === null || $bearer === '') {
            return null;
        }

        $token = PersonalAccessToken::findToken($bearer);

        if ($token === null || $token->tenant_id === null) {
            return null;
        }

        // TENANCY: the tenants table is never tenant-scoped — it is the table
        // the scope is derived from.
        return Tenant::find($token->tenant_id);
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
