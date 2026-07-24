<?php

use App\Http\Middleware\EnsureRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->throttleApi();

        // Runs before EVERYTHING on both stacks. The tenant scope throws on
        // any tenant-owned query with nothing bound, so this cannot be
        // opt-in per route — a route that forgot it would 500 rather than
        // leak, but it would still be broken. Prepended so it is also ahead
        // of auth: which user you are is meaningless until we know which
        // workspace is being addressed.
        // Global, not per-group: one registration covers web and api, and
        // registering it in both places would run it twice per API request.
        $middleware->prepend(\App\Http\Middleware\ResolveTenant::class);

        $middleware->alias([
            'role' => EnsureRole::class,
            'agent' => \App\Http\Middleware\AuthenticateAgent::class,
        ]);

        // Pure JSON API, no Blade login page — never redirect an
        // unauthenticated request to a `login` route that doesn't exist,
        // just let it fall through to a clean 401 JSON response.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
