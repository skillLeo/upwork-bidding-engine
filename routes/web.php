<?php

use Illuminate\Support\Facades\Route;

// Every non-API path serves the same Vue SPA shell — Vue Router handles the
// actual client-side routing from there. /api/* is a completely separate
// route file (routes/api.php) mounted under its own prefix, so it's never
// reachable through this catch-all.
Route::get('/{any}', function () {
    return response()
        ->view('app')
        // The SPA shell must never be cached — not by the browser, and not by
        // Hostinger's CDN edge (which honours Surrogate-Control / CDN-Cache-Control).
        // A stale shell references hashed asset files from an older build that no
        // longer exist, so it must always be re-fetched fresh from origin.
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
        ->header('CDN-Cache-Control', 'no-store')
        ->header('Surrogate-Control', 'no-store')
        ->header('Pragma', 'no-cache');
})->where('any', '^(?!api).*$');
