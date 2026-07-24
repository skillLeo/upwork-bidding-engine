<?php

namespace App\Http\Controllers\Platform;

use App\Enums\AuthEventType;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuthEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Read-only "become this user" (P5). Write-blocking and the 60-minute
 * auto-expiry are enforced by ImpersonationGuard on every authenticated
 * route, not here — this controller only mints and revokes the token.
 */
class ImpersonationController extends Controller
{
    private const DURATION_MINUTES = 60;

    /**
     * Restricted to platform_owner specifically — canReadTenantContent() —
     * not every platform role. Reading a tenant's metadata (TenantController)
     * is safe for support/billing; LITERALLY BECOMING a tenant user exposes
     * everything that role can see, which is a materially bigger grant than
     * the content wall TenantController enforces in its queries.
     */
    public function start(Request $request, int $tenant, int $user): JsonResponse
    {
        $staff = $request->user();

        abort_unless(
            $staff->platformRole()?->canReadTenantContent() ?? false,
            403,
            'Only the platform owner may impersonate — support and billing are walled off from tenant content.',
        );

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        // TENANCY: resolving the impersonation target is a deliberate
        // cross-tenant platform-console lookup.
        [$t, $target] = Tenancy::asPlatform(function () use ($tenant, $user) {
            $t = Tenant::withTrashed()->findOrFail($tenant);
            $target = $t->users()->whereKey($user)->first();

            return [$t, $target];
        });

        if ($t->trashed()) {
            throw ValidationException::withMessages(['tenant' => ['This workspace has been deleted.']]);
        }

        if ($target === null) {
            throw ValidationException::withMessages(['user' => ['That person is not a member of this workspace.']]);
        }

        $token = $target->createToken('impersonation');
        $token->accessToken->forceFill([
            'impersonator_id' => $staff->id,
            'impersonation_reason' => $validated['reason'],
            'impersonation_expires_at' => now()->addMinutes(self::DURATION_MINUTES),
            'tenant_id' => $t->id,
        ])->save();

        // auth_events has no "target user" column of its own — email_attempted
        // (normally the login email a failed attempt tried) carries WHO was
        // impersonated on the STAFF member's own audit row, reusing the
        // existing field rather than adding a new one for one use site.
        Tenancy::runAs($t, fn () => AuthEvent::record(
            AuthEventType::ImpersonationStarted,
            user: $staff,
            emailAttempted: $target->email,
            request: $request,
        ));

        return response()->json(['data' => [
            'token' => $token->plainTextToken,
            'user' => new UserResource($target),
            'reason' => $validated['reason'],
            'expires_at' => $token->accessToken->impersonation_expires_at->toIso8601String(),
            'tenant' => ['id' => $t->id, 'name' => $t->name, 'slug' => $t->slug],
        ]]);
    }

    /**
     * Called USING the impersonation token itself — the frontend swaps back
     * to the platform staff member's own token client-side once this
     * succeeds. Exempted from ImpersonationGuard's write-block by route name
     * (platform.impersonation.end) so ending a session is always possible.
     */
    public function end(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        abort_unless(
            $token instanceof PersonalAccessToken && $token->impersonator_id !== null,
            422,
            'This session is not impersonating anyone.',
        );

        $staff = User::find($token->impersonator_id);
        $target = $request->user();

        if ($staff !== null) {
            AuthEvent::record(
                AuthEventType::ImpersonationEnded,
                user: $staff,
                emailAttempted: $target->email,
                request: $request,
            );
        }

        $token->delete();

        return response()->json(['data' => ['message' => 'Impersonation ended.']]);
    }
}
