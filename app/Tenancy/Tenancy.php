<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Closure;

/**
 * The public face of the tenancy layer.
 *
 * A thin static wrapper over the TenantContext singleton so that
 * cross-tenant work reads as `Tenancy::asPlatform(...)` at the call site —
 * short enough that nobody is tempted to reach for withoutGlobalScope()
 * instead, and greppable as one exact string.
 *
 * There is no state here. Everything delegates to the singleton, so a test
 * that binds a tenant through TenantContext directly is seen by this too.
 */
class Tenancy
{
    protected static function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    public static function current(): ?Tenant
    {
        return static::context()->get();
    }

    public static function id(): ?int
    {
        return static::context()->id();
    }

    public static function check(): bool
    {
        return static::context()->get() !== null;
    }

    /**
     * Run $fn as $tenant, restoring the previous tenant afterwards even if
     * $fn throws.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $fn
     * @return TReturn
     */
    public static function runAs(Tenant $tenant, Closure $fn): mixed
    {
        return static::context()->runAs($tenant, $fn);
    }

    /**
     * Run $fn with the tenant scope OFF.
     *
     * THE ONLY sanctioned way to query across tenants. Every cross-tenant
     * read in the app goes through here and nothing else, so this string is
     * a complete list of where isolation is deliberately set aside —
     * enforced by TenancyGuardTest, which fails on any bypass lacking a
     * "TENANCY:" justification comment.
     *
     * Note this switches off the READ scope only. Writes still refuse to
     * guess an owner: creating a tenant-owned row in platform context throws
     * unless tenant_id is passed explicitly.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $fn
     * @return TReturn
     */
    public static function asPlatform(Closure $fn): mixed
    {
        return static::context()->asPlatform($fn);
    }

    public static function isPlatform(): bool
    {
        return static::context()->isPlatform();
    }
}
