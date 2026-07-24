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
#[Hidden(['password', 'remember_token', 'two_factor_code', 'two_factor_challenge', 'google2fa_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;
    use HasRoles {
        hasPermissionTo as protected spatieHasPermissionTo;
    }

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
            // Encrypted at rest via Eloquent's own cast (not SettingsService's
            // Crypt pattern — this column lives on users, not settings).
            'google2fa_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            // Each entry is itself a Hash::make() of one recovery code —
            // this cast is just JSON array (de)serialization, not the hash.
            'two_factor_recovery_codes' => 'array',
        ];
    }

    /**
     * TOTP is enrolled AND confirmed — never true from display alone (see
     * TotpController::confirm()). This is the "recommended" second factor;
     * hasEmailOtp() is the older, still-supported one.
     */
    public function hasTotpEnabled(): bool
    {
        return $this->google2fa_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function hasEmailOtp(): bool
    {
        return (bool) $this->two_factor_enabled;
    }

    /**
     * False only for an account created entirely via Google with no invite
     * (P6) — genuinely no password exists, not merely an unused random one.
     * Unlinking Google on such an account would be a permanent lockout, so
     * this is what SocialAuthController's unlink refusal checks.
     */
    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    /**
     * Any second factor at all — what require_2fa (P6) checks to decide
     * whether a member must be routed to enrolment before anything else.
     */
    public function hasAnyTwoFactor(): bool
    {
        return $this->hasTotpEnabled() || $this->hasEmailOtp();
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isBidder(): bool
    {
        return $this->role === UserRole::Bidder;
    }

    /**
     * Deny-aware permission check. Spatie's Gate::before delegates every
     * ->can() to checkPermissionTo(), which lands here — so intercepting AT
     * THE MODEL is ordering-proof, unlike a second Gate::before (package
     * providers boot first, so Spatie's "granted" would win before an app
     * hook ever ran; learned by failing test). A per-user deny row beats any
     * role or direct grant; the workspace owner is exempt, which is the lock
     * that keeps the repair path open.
     */
    public function hasPermissionTo($permission, $guardName = null): bool
    {
        $name = \is_string($permission)
            ? $permission
            : ($permission->name ?? (string) $permission);

        $tenant = \App\Tenancy\Tenancy::current();

        if ($tenant !== null
            && $tenant->owner_user_id !== $this->id
            && in_array($name, \App\Models\PermissionDeny::forUser($this->id), true)) {
            return false;
        }

        return $this->spatieHasPermissionTo($permission, $guardName);
    }

    /**
     * Role grants + direct grants, minus this user's denies for the current
     * tenant (owner exempt). This is what the frontend's can() helper runs
     * on, and it must agree exactly with the server-side Gate resolution.
     *
     * @return array<int, string>
     */
    public function effectivePermissions(): array
    {
        $granted = $this->getAllPermissions()->pluck('name')->values()->all();

        $tenant = \App\Tenancy\Tenancy::current();

        if ($tenant === null || $tenant->owner_user_id === $this->id) {
            return $granted;
        }

        $denied = \App\Models\PermissionDeny::forUser($this->id);

        return array_values(array_diff($granted, $denied));
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

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }
}
