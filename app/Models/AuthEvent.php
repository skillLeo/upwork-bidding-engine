<?php

namespace App\Models;

use App\Enums\AuthEventType;
use App\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

/**
 * Append-only authentication audit log.
 *
 * The append-only rule is enforced here, not merely documented: updating or
 * deleting a row throws. An audit trail a compromised admin can quietly
 * rewrite is worse than none, because it is trusted.
 *
 * NOT tenant-scoped, deliberately. A failed login has no tenant to scope by
 * — the attacker never got far enough to resolve one — and scoping the table
 * would hide exactly the rows an investigation needs. tenant_id is recorded
 * when known and filtered explicitly at the query site instead.
 */
class AuthEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'tenant_id', 'email_attempted', 'event',
        'ip', 'user_agent', 'approx_location',
    ];

    protected function casts(): array
    {
        return [
            'event' => AuthEventType::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('auth_events is append-only: rows cannot be modified.');
        });

        static::deleting(function () {
            throw new \RuntimeException('auth_events is append-only: rows cannot be deleted.');
        });
    }

    /**
     * The single writer. Everything auth-related routes through here so the
     * request context (IP, agent, device, location) is captured identically
     * every time rather than being re-derived at each call site.
     */
    public static function record(
        AuthEventType $event,
        ?User $user = null,
        ?string $emailAttempted = null,
        ?Request $request = null,
    ): self {
        $request ??= request();
        $agent = $request?->userAgent();
        $ip = $request?->ip();

        return static::create([
            'user_id' => $user?->id,
            'tenant_id' => Tenancy::id(),
            'email_attempted' => $emailAttempted ?? $user?->email,
            'event' => $event->value,
            'ip' => $ip,
            'user_agent' => $agent ? mb_substr($agent, 0, 1000) : null,
            'approx_location' => $ip ? app(\App\Services\Auth\IpLocator::class)->locate($ip) : null,
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
