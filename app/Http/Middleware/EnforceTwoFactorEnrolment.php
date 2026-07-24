<?php

namespace App\Http\Middleware;

use App\Services\SettingsService;
use App\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Workspace require_2fa (P6.2): when on, a member with no second factor
 * enrolled (neither TOTP nor email OTP) is refused every route except the
 * small self-service allowlist below — hard-blocked here, with the frontend
 * router redirecting to enrolment on the same 403 code (defense in depth,
 * same pattern as every other permission gate in this app: hidden
 * client-side AND enforced server-side).
 *
 * The tenant's OWNER is exempt unconditionally — not a loophole, the spec's
 * own carve-out: "exclude the owner from being locked out by their own
 * setting only if they are the last remaining owner." This app's data model
 * has exactly one owner_user_id per tenant, so the owner IS always the last
 * (only) remaining owner — the exemption is never broader than that.
 */
class EnforceTwoFactorEnrolment
{
    /**
     * Reachable with no second factor: read your own identity, sign out,
     * manage your own account and 2FA, leave/switch workspaces. Nothing
     * else — an unenrolled member truly cannot reach product routes.
     */
    private const ALLOWED_PATHS = [
        'api/me',
        'api/auth/logout',
        'api/profile',
        'api/profile/password',
        'api/profile/avatar',
        'api/profile/two-factor',
        'api/profile/totp/*',
        'api/profile/sessions*',
        'api/profile/workspaces*',
        'api/branding',
        'api/health/ping',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenant = Tenancy::current();

        if ($user === null || $tenant === null) {
            return $next($request);
        }

        if (! (bool) app(SettingsService::class)->get('require_2fa', false)) {
            return $next($request);
        }

        if ($tenant->owner_user_id === $user->id || $user->hasAnyTwoFactor()) {
            return $next($request);
        }

        if ($request->is(...self::ALLOWED_PATHS)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'This workspace requires two-factor authentication. Enrol to continue.',
            'code' => 'must_enroll_2fa',
        ], 403);
    }
}
