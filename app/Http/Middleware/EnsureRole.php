<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level enforcement of role access (e.g. `role:admin`). This is
 * defense in depth, not the only gate — the Settings screen is also hidden
 * client-side, but a bidder token must get a hard 403 here regardless of
 * what the frontend renders.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $allowed = array_map(fn (string $role) => UserRole::from($role), $roles);

        if (! in_array($user->role, $allowed, true)) {
            return response()->json(['message' => 'This action is unauthorized for your role.'], 403);
        }

        return $next($request);
    }
}
