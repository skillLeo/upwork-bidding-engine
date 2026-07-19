<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer auth for the Agent API — the locked, limited door OpenClaw uses
 * to answer WhatsApp questions about leads. Uses its own token
 * (agent_api_token), never the app's user auth, and every authenticated
 * call is logged with its endpoint and params so the door has an audit
 * trail. Bad token = 401 JSON, never an HTML redirect.
 */
class AuthenticateAgent
{
    public function __construct(protected SettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $expected = $this->settings->agentApiToken();

        if (! $expected || ! hash_equals($expected, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        ActivityLog::record('agent_api_call', meta: [
            'endpoint' => $request->method().' '.$request->path(),
            'params' => $request->except(['token']),
        ]);

        return $next($request);
    }
}
