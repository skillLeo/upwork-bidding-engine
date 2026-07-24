<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applied to the whole authenticated API surface (not just /platform), since
 * an impersonation token acts as the TENANT user across the entire app —
 * "cannot write anything" has to mean everywhere, not just inside the
 * platform console. A normal token (impersonator_id null) passes straight
 * through with no extra cost.
 */
class ImpersonationGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || $token->impersonator_id === null) {
            return $next($request);
        }

        $expiresAt = $token->impersonation_expires_at;

        if ($expiresAt !== null && Carbon::parse($expiresAt)->isPast()) {
            $token->delete();

            return response()->json(['message' => 'This impersonation session expired after 60 minutes.'], 401);
        }

        $isWrite = ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
        $isEndRoute = $request->route()?->named('platform.impersonation.end') ?? false;

        if ($isWrite && ! $isEndRoute) {
            return response()->json(['message' => 'This session is impersonating a user and is read-only.'], 403);
        }

        return $next($request);
    }
}
