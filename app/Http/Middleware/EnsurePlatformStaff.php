<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The ONLY gate on /platform/*. Platform staff is a column no tenant-facing
 * UI can ever write (see PlatformRole's docblock) — completely separate
 * namespace from tenant roles/permissions, so nothing a workspace does to
 * its own roles can ever grant this.
 */
class EnsurePlatformStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isPlatformStaff()) {
            return response()->json(['message' => 'Platform staff only.'], 403);
        }

        return $next($request);
    }
}
