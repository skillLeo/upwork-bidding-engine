<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Tenancy\MissingTenantException;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BelongsToTenant, but tenant_id NULL is a real value rather than an orphan.
 *
 * Only `settings` uses this. A settings row with tenant_id NULL is the
 * PLATFORM DEFAULT — the value every tenant falls back to when it has no
 * override of its own — so the read scope has to be
 * "mine OR platform", not "mine".
 *
 * The plain BelongsToTenant scope would hide every platform default and the
 * whole app would fall back to the hardcoded values in SettingsService,
 * silently, with no error anywhere. That is why this is a separate trait
 * rather than a flag on the other one: the difference is easy to miss and
 * expensive to miss.
 *
 * Writes still refuse to guess: creating a row with no tenant bound throws,
 * exactly as in BelongsToTenant. A platform default is created deliberately,
 * by passing tenant_id => null explicitly inside platform context.
 */
trait BelongsToTenantOrPlatform
{
    public static function bootBelongsToTenantOrPlatform(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            $context = app(TenantContext::class);

            if ($context->isPlatform()) {
                return;
            }

            $tenantId = $context->id();

            if ($tenantId === null) {
                throw MissingTenantException::forQuery(static::class);
            }

            $table = $query->getModel()->getTable();

            $query->where(function (Builder $q) use ($table, $tenantId) {
                $q->where($table.'.tenant_id', $tenantId)
                    ->orWhereNull($table.'.tenant_id');
            });
        });

        static::creating(function (Model $model) {
            // An explicit null is a deliberate platform default, not a
            // missing value, so only an ABSENT attribute gets filled in.
            if (array_key_exists('tenant_id', $model->getAttributes())) {
                return;
            }

            $context = app(TenantContext::class);
            $tenantId = $context->id();

            if ($tenantId === null) {
                throw MissingTenantException::forCreate(static::class, $context->isPlatform());
            }

            $model->setAttribute('tenant_id', $tenantId);
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
