<?php

namespace App\Tenancy;

use RuntimeException;

/**
 * Thrown when tenant-owned data is touched with no tenant bound.
 *
 * This is always a bug in the caller, never a user error, so the message
 * names the model and the likely cause rather than being generic — the usual
 * culprit is a queued job or console command that forgot to bind the context
 * before doing work.
 */
class MissingTenantException extends RuntimeException
{
    public static function forQuery(string $model): self
    {
        return new self(
            "No tenant is bound, so [{$model}] cannot be queried safely. "
            .'A web request should have gone through the ResolveTenant middleware; '
            .'a queued job must rebind the tenant in handle(); a console command must '
            .'bind one (see --tenant). To query across tenants deliberately, wrap the '
            .'call in TenantContext::asPlatform().'
        );
    }

    public static function forCreate(string $model, bool $inPlatformContext): self
    {
        $extra = $inPlatformContext
            ? 'Platform context skips the read scope but never guesses an owner for a write: '
                .'pass tenant_id explicitly.'
            : 'Bind a tenant before creating the record.';

        return new self("No tenant is bound, so [{$model}] cannot be created. {$extra}");
    }
}
