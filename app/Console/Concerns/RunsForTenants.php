<?php

namespace App\Console\Concerns;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;

/**
 * Tenant binding for console commands.
 *
 * This host is Hostinger shared hosting, permanently — no VPS, no Horizon,
 * no persistent worker. There is exactly ONE cron entry calling schedule:run
 * every minute, and every scheduled task runs in-process inside it. So a
 * scheduled command cannot be "one scheduled entry per tenant": it has to
 * loop the tenants itself, inside its single invocation.
 *
 *   --tenant=slug   run for that one tenant (manual, targeted)
 *   (omitted)       run for EVERY operable tenant, in id order
 *
 * Suspended tenants are skipped entirely — no polling, no AI spend, no
 * notifications. That is the kill switch for a non-paying workspace, and it
 * belongs here rather than in each command.
 *
 * @mixin \Illuminate\Console\Command
 */
trait RunsForTenants
{
    /**
     * Add to the command's signature: {--tenant= : ...}
     */
    protected function tenantsToRun(): \Illuminate\Support\Collection
    {
        $slug = $this->option('tenant');

        // TENANCY: the tenants table is never tenant-scoped — it is the table
        // the scope is derived from.
        return app(TenantContext::class)->asPlatform(function () use ($slug) {
            $query = Tenant::query()->orderBy('id');

            if (is_string($slug) && $slug !== '') {
                $query->where('slug', $slug);
            }

            return $query->get()->filter(fn (Tenant $t) => $t->isOperable())->values();
        });
    }

    /**
     * Run $fn once per tenant with the context bound, and return the WORST
     * exit code any tenant produced — a failure for one tenant has to surface
     * as a failed command, or a broken workspace looks healthy from the cron.
     *
     * An exception from one tenant is reported and the loop CONTINUES: on a
     * single shared cron, one tenant's broken API key must not stop every
     * other tenant's intake for the rest of the day.
     *
     * @param  Closure(Tenant): int  $fn
     */
    protected function forEachTenant(Closure $fn): int
    {
        $tenants = $this->tenantsToRun();

        if ($tenants->isEmpty()) {
            $slug = $this->option('tenant');
            $this->warn($slug ? "No operable tenant with slug [{$slug}]." : 'No operable tenants.');

            return 0;
        }

        $context = app(TenantContext::class);
        $worst = self::SUCCESS;

        foreach ($tenants as $tenant) {
            try {
                $code = (int) ($context->runAs($tenant, fn () => $fn($tenant)) ?? self::SUCCESS);
                $worst = max($worst, $code);
            } catch (\Throwable $e) {
                report($e);
                $this->error("[{$tenant->slug}] failed: ".$e->getMessage());
                $worst = max($worst, self::FAILURE);
            }
        }

        return $worst;
    }

    /**
     * Run $fn against exactly ONE tenant — for commands that operate on a
     * single target (one lead id, one Vollna token) where looping every
     * workspace would be meaningless. Uses --tenant, falling back to the
     * configured default.
     *
     * @param  Closure(Tenant): int  $fn
     */
    protected function forOneTenant(Closure $fn): int
    {
        $slug = $this->option('tenant') ?: config('tenancy.default_tenant_slug');

        // TENANCY: the tenants table is never tenant-scoped.
        $tenant = app(TenantContext::class)->asPlatform(
            fn () => Tenant::where('slug', $slug)->first()
        );

        if ($tenant === null) {
            $this->error("No tenant with slug [{$slug}]. Pass --tenant=<slug>.");

            return self::FAILURE;
        }

        return app(TenantContext::class)->runAs($tenant, fn () => $fn($tenant));
    }

    /**
     * Run $fn with NO tenant and the scope off — for commands that write
     * platform-level settings (the scoring rubric, the drafting skill, the
     * VAPID keypair). Those belong to the product, not to any workspace.
     *
     * @param  Closure(): int  $fn
     */
    protected function asPlatform(Closure $fn): int
    {
        $context = app(TenantContext::class);
        $previous = $context->get();
        $context->forget();

        try {
            return $context->asPlatform($fn);
        } finally {
            $context->set($previous);
        }
    }
}
