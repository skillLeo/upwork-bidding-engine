<?php

use Illuminate\Support\Facades\Route;

// Every non-API path serves the same Vue SPA shell — Vue Router handles the
// actual client-side routing from there. /api/* is a completely separate
// route file (routes/api.php) mounted under its own prefix, so it's never
// reachable through this catch-all.
Route::get('/{any}', function () {
    return response()
        ->view('app')
        ->header('Cache-Control', 'no-store, must-revalidate');
})->where('any', '^(?!api).*$');
