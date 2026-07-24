<?php

namespace App\Models;

use App\Authorization\PlatformRole;
use App\Enums\UserRole;
use App\Models\Tenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'role', 'platform_role', 'avatar_path', 'two_factor_enabled', 'two_factor_attempts'])]
#[Hidden(['password', 'remember_token', 'two_factor_code', 'two_factor_challenge'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Spatie's HasRoles defaults to the 'web' guard; our API auth is the
     * 'sanctum' guard, so pin it or every permission check silently looks
     * under the wrong guard and finds nothing.
     */
    protected string $guard_name = 'web';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'two_factor_enabled' => 'boolean',
            'two_factor_expires_at' => 'datetime',
            'two_factor_attempts' => 'integer',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isBidder(): bool
    {
        return $this->role === UserRole::Bidder;
    }

    public function platformRole(): ?PlatformRole
    {
        return $this->platform_role !== null
            ? PlatformRole::tryFrom($this->platform_role)
            : null;
    }

    public function isPlatformStaff(): bool
    {
        return $this->platform_role !== null;
    }

    /**
     * The workspaces this user belongs to. A user can be in more than one —
     * an agency bidder working for two clients is the obvious case.
     *
     * @return BelongsToMany<Tenant, $this>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users')->withPivot('joined_at');
    }

    /**
     * @return HasMany<ActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }
}
