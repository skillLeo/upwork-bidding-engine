<?php

namespace Tests;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The tenant every test runs as unless it says otherwise.
     *
     * Tenancy is enforced by a global scope that THROWS when nothing is
     * bound, which is deliberate — a silent unscoped query is the failure
     * this whole layer exists to prevent. In a web request the middleware
     * binds the tenant; in a test there is no middleware, so this does it.
     *
     * TenantIsolationTest ignores this and builds its own tenants, which is
     * the point: the isolation guarantee has to be proven against two real
     * tenants, not against the convenience default.
     */
    protected ?Tenant $tenant = null;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->usesRefreshDatabase()) {
            $this->tenant = Tenant::firstOrCreate(
                ['slug' => 'skillleo'],
                ['name' => 'SkillLeo', 'plan' => 'internal', 'status' => Tenant::STATUS_ACTIVE],
            );

            app(TenantContext::class)->set($this->tenant);
        }
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            app(TenantContext::class)->forget();
        }

        parent::tearDown();
    }

    /**
     * True when the test case refreshes the database, i.e. there are tables
     * to create a tenant in. A handful of pure-unit tests do not.
     */
    protected function usesRefreshDatabase(): bool
    {
        foreach (class_uses_recursive(static::class) as $trait) {
            if (str_contains($trait, 'RefreshDatabase') || str_contains($trait, 'DatabaseTransactions')) {
                return true;
            }
        }

        return false;
    }
}
