<?php

namespace App\Http\Controllers;

use App\Enums\AuthEventType;
use App\Models\AuthEvent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * "Devices and sessions" — the list of live tokens on an account, and the
 * ability to kill them.
 *
 * Everything here is scoped to the CURRENT USER, never to a user id from the
 * request. Letting a caller name the account whose sessions to revoke would
 * hand any authenticated user a logout weapon against everyone else.
 */
class SessionController extends Controller
{
    /** Auth events shown on the profile page. */
    private const RECENT_EVENT_LIMIT = 20;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentId = $request->user()?->currentAccessToken()?->id;

        $sessions = $user->tokens()
            ->orderByRaw('COALESCE(last_used_at, created_at) desc')
            ->get()
            ->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'device_label' => $token->device_label ?? 'Unknown device',
                'approx_location' => $token->approx_location,
                'ip_address' => $token->ip_address,
                'last_used_at' => ($token->last_used_at ?? $token->created_at)?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
                'is_current' => $token->id === $currentId,
            ]);

        // Not tenant-scoped: a user's own security history spans every
        // workspace they belong to, and hiding part of it would make the
        // list actively misleading.
        $events = AuthEvent::where('user_id', $user->id)
            ->latest('id')
            ->limit(self::RECENT_EVENT_LIMIT)
            ->get()
            ->map(fn (AuthEvent $event) => [
                'id' => $event->id,
                'event' => $event->event->value,
                'label' => $event->event->label(),
                'ip' => $event->ip,
                'approx_location' => $event->approx_location,
                'created_at' => $event->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => [
            'sessions' => $sessions,
            'events' => $events,
        ]]);
    }

    /**
     * Revoke one session. Scoped through the user's own relation, so a token
     * id belonging to somebody else simply is not found.
     */
    public function destroy(Request $request, int $token): JsonResponse
    {
        $target = $request->user()->tokens()->whereKey($token)->first();

        if ($target === null) {
            return response()->json(['message' => 'That session no longer exists.'], 404);
        }

        $target->delete();

        AuthEvent::record(AuthEventType::TokenRevoked, user: $request->user(), request: $request);

        return response()->json(['data' => ['message' => 'Signed that device out.']]);
    }

    /**
     * Sign out everywhere ELSE — the current session survives, so the person
     * doing the securing is not immediately logged out mid-panic.
     */
    public function destroyOthers(Request $request): JsonResponse
    {
        $currentId = $request->user()->currentAccessToken()?->id;

        $revoked = $request->user()->tokens()
            ->when($currentId !== null, fn ($q) => $q->where('id', '!=', $currentId))
            ->delete();

        AuthEvent::record(AuthEventType::TokenRevoked, user: $request->user(), request: $request);

        return response()->json(['data' => [
            'message' => $revoked === 1 ? 'Signed 1 other device out.' : "Signed {$revoked} other devices out.",
            'revoked' => $revoked,
        ]]);
    }

    /**
     * The "this wasn't me" link from the new-device email.
     *
     * Unauthenticated by necessity — someone reading that email in a mail
     * client has no session — so it relies on Laravel's signed URL (the
     * `signed` middleware verifies the HMAC and the 7-day expiry). Revoking
     * ALL tokens including the attacker's is the whole point, so this one
     * deliberately does not spare the current session.
     */
    public function revokeAllFromEmail(Request $request, User $user): JsonResponse
    {
        $revoked = $user->tokens()->delete();

        AuthEvent::record(AuthEventType::TokenRevoked, user: $user, request: $request);

        return response()->json(['data' => [
            'message' => "Signed out of {$revoked} session(s). Change your password now.",
        ]]);
    }
}
