<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Tenancy\MissingTenantException;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applies tenant isolation structurally rather than by discipline.
 *
 * Two hooks:
 *   - a global scope, so every query on this model gains a tenant predicate
 *     without any caller remembering to add one;
 *   - a creating hook, so every insert carries the right tenant_id without
 *     any caller passing it.
 *
 * And one deliberate cruelty: with no tenant resolved and no explicit
 * platform context, both hooks THROW. A silent unscoped query is the failure
 * mode this whole trait exists to prevent, and an exception in development
 * costs minutes where a cross-tenant read costs a company.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
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

            // Qualified so the predicate survives joins onto tables that also
            // have a tenant_id column.
            $query->where($query->getModel()->getTable().'.tenant_id', $tenantId);
        });

        static::creating(function (Model $model) {
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }

            $context = app(TenantContext::class);
            $tenantId = $context->id();

            if ($tenantId === null) {
                // Platform context is allowed to write only when it supplies
                // tenant_id explicitly — otherwise the row belongs to nobody
                // and becomes invisible to every tenant forever.
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
