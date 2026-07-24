<?php

namespace App\Models;

use App\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;

/**
 * A per-user permission DENY — beats any role grant for that user in that
 * workspace. See the Gate::before hook in AppServiceProvider for the
 * enforcement, and the migration for why this exists outside Spatie.
 *
 * Not tenant-scoped by the global trait: rows are always written and read
 * with an explicit tenant_id (composite key), and the enforcement hook must
 * work even in contexts where the scope would throw.
 */
class PermissionDeny extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['tenant_id', 'user_id', 'permission', 'created_at'];

    /**
     * The denied permission names for a user in the CURRENT tenant.
     * Cached per request (static) because the Gate::before hook runs on
     * every single ->can() call — often dozens per request — and they must
     * not each cost a query.
     *
     * @var array<string, array<int, string>>
     */
    protected static array $requestCache = [];

    /**
     * @return array<int, string>
     */
    public static function forUser(int $userId): array
    {
        $tenantId = Tenancy::id();

        if ($tenantId === null) {
            return [];
        }

        $key = $tenantId.':'.$userId;

        return static::$requestCache[$key] ??= static::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->pluck('permission')
            ->all();
    }

    public static function flushRequestCache(): void
    {
        static::$requestCache = [];
    }
}
