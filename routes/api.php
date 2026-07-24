<?php

use App\Http\Controllers\AgentScoreController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DiagnosticsController;
use App\Http\Controllers\HealthPingController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SavedFilterController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\VollnaWebhookController;
use App\Http\Middleware\VerifyVollnaSecret;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public webhooks — secret-verified (or handshake-verified) + rate limited.
| Nothing else in this file is reachable without a Sanctum token.
|--------------------------------------------------------------------------
*/
Route::post('/vollna-hook', VollnaWebhookController::class)
    ->middleware([VerifyVollnaSecret::class, 'throttle:webhooks']);

// OpenClaw's "score this brief" path — same rubric, same brain as the
// pipeline. Token-checked inside the controller (OpenClaw bearer token).
Route::post('/agent/score', AgentScoreController::class)
    ->middleware('throttle:webhooks');

// The Agent API — OpenClaw's ONLY door into the app's data, guarded by
// its own bearer token (agent_api_token). Read leads/clients/summary
// plus one status POST; deliberately nothing else (no settings, no
// keys, no deletes). Every call is logged by the middleware.
Route::prefix('agent')->middleware(['agent', 'throttle:60,1'])->group(function () {
    Route::get('/leads', [\App\Http\Controllers\AgentApiController::class, 'leads']);
    Route::get('/leads/{lead}', [\App\Http\Controllers\AgentApiController::class, 'lead']);
    Route::get('/summary', [\App\Http\Controllers\AgentApiController::class, 'summary']);
    Route::get('/clients/{client}', [\App\Http\Controllers\AgentApiController::class, 'client']);
    Route::post('/leads/{lead}/status', [\App\Http\Controllers\AgentApiController::class, 'updateStatus']);
    Route::post('/leads/{lead}/rescore', [\App\Http\Controllers\AgentApiController::class, 'rescore']);
    Route::post('/leads/{lead}/rewrite', [\App\Http\Controllers\AgentApiController::class, 'rewrite']);
});

// Product name + logo — the sign-in screen needs these before anyone has
// a token, so this stays outside auth:sanctum entirely.
Route::get('/branding', [SettingsController::class, 'branding']);

// Liveness probe for an external uptime monitor. Unauthenticated by
// necessity — a monitor holding a Sanctum token would be a permanent
// credential sitting in a third party's config. See HealthPingController
// for why this exists alongside the outbound ops:heartbeat ping.
Route::get('/health/ping', HealthPingController::class)
    ->middleware('throttle:30,1');

// Outbound WhatsApp goes through OpenClaw (see OpenClawService), not Meta's
// Cloud API, so there is no inbound Meta webhook to receive here.

/*
|--------------------------------------------------------------------------
| Auth
|--------------------------------------------------------------------------
*/
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

// Passwordless dev sign-in. The controller 404s unless skillleo.dev_quick_login
// is on, so this is inert in a deployment that hasn't opted in.
Route::post('/auth/dev-login', [AuthController::class, 'devLogin'])
    ->middleware('throttle:webhooks');

Route::post('/auth/verify-otp', [AuthController::class, 'verifyOtp'])
    ->middleware('throttle:otp');
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:password-reset');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:login');

// The "this wasn't me" link in the new-device email. Unauthenticated by
// necessity — a mail client has no session — so the `signed` middleware is
// what makes it safe: Laravel verifies the HMAC and the 7-day expiry, and a
// forged or replayed link is rejected before this ever runs.
Route::get('/auth/revoke-all/{user}', [SessionController::class, 'revokeAllFromEmail'])
    ->middleware(['signed', 'throttle:webhooks'])
    ->name('auth.revoke-all');

// Invitation accept — reached from the email link with no session. The raw
// token is the credential (looked up by hash, single-use). Rate limited
// because it is public and token-guessable in theory.
Route::get('/invitations/show', [\App\Http\Controllers\InvitationAcceptController::class, 'show'])
    ->middleware('throttle:webhooks');
Route::post('/invitations/accept', [\App\Http\Controllers\InvitationAcceptController::class, 'accept'])
    ->middleware('throttle:webhooks');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    /*
    |----------------------------------------------------------------------
    | Profile — every authenticated user manages their own account (name,
    | email, password, 2FA, avatar), bidder and admin alike.
    |----------------------------------------------------------------------
    */
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/profile/password', [ProfileController::class, 'updatePassword']);
    Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar']);
    Route::put('/profile/two-factor', [ProfileController::class, 'toggleTwoFactor']);

    // Devices and sessions — always scoped to the caller's own account.
    Route::get('/profile/sessions', [SessionController::class, 'index']);
    Route::delete('/profile/sessions/others', [SessionController::class, 'destroyOthers']);
    Route::delete('/profile/sessions/{token}', [SessionController::class, 'destroy']);

    /*
    |----------------------------------------------------------------------
    | Leads — readable by admin + bidder, status updates by either
    | (bidder is the primary actor; admin retains full access), rescore
    | is admin-only (it re-spends the AI call).
    |----------------------------------------------------------------------
    */
    Route::get('/leads', [LeadController::class, 'index']);
    Route::post('/leads/bulk-status', [LeadController::class, 'bulkStatus']);
    Route::post('/leads/bulk-favorite', [LeadController::class, 'bulkFavorite']);
    Route::get('/leads/{lead}', [LeadController::class, 'show']);
    Route::post('/leads/{lead}/status', [LeadController::class, 'updateStatus']);
    Route::put('/leads/{lead}/proposal', [LeadController::class, 'updateProposal']);
    Route::get('/leads/{lead}/proposal-versions', [LeadController::class, 'proposalVersions']);
    Route::post('/leads/{lead}/proposal/ai-edit', [LeadController::class, 'aiEditProposal']);
    Route::post('/leads/{lead}/proposal/ai-edit/accept', [LeadController::class, 'acceptAiEditProposal']);
    Route::post('/leads/{lead}/regenerate-score', [LeadController::class, 'regenerateScore']);
    Route::post('/leads/{lead}/regenerate-proposal', [LeadController::class, 'regenerateProposal']);
    Route::post('/leads/{lead}/favorite', [LeadController::class, 'toggleFavorite']);
    Route::post('/leads/{lead}/client-view', [LeadController::class, 'updateClientView']);
    Route::post('/leads/{lead}/outcome', [LeadController::class, 'updateOutcome']);
    // rescore authorizes via LeadPolicy inside the controller (it re-spends
    // an AI call). sync-vollna can prune leads, so it needs leads.delete.
    Route::post('/leads/{lead}/rescore', [LeadController::class, 'rescore']);
    Route::post('/leads/sync-vollna', [LeadController::class, 'syncVollna'])->middleware('permission:leads.delete');

    /*
    |----------------------------------------------------------------------
    | Saved lead filters — shared account-wide, same as Settings, rather
    | than owned per-user (this is a single-bidder tool, not multi-tenant).
    |----------------------------------------------------------------------
    */
    /*
    |----------------------------------------------------------------------
    | In-app notifications (the bell) — account-wide, both roles.
    |----------------------------------------------------------------------
    */
    Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{notification}/read', [\App\Http\Controllers\NotificationController::class, 'markRead']);

    // Web Push (lock-screen / closed-browser notifications).
    Route::get('/push/vapid-key', [\App\Http\Controllers\PushController::class, 'vapidKey']);
    Route::post('/push/subscribe', [\App\Http\Controllers\PushController::class, 'subscribe']);
    Route::post('/push/unsubscribe', [\App\Http\Controllers\PushController::class, 'unsubscribe']);

    Route::get('/saved-filters', [SavedFilterController::class, 'index']);
    Route::post('/saved-filters', [SavedFilterController::class, 'store']);
    Route::put('/saved-filters/{savedFilter}', [SavedFilterController::class, 'update']);
    Route::delete('/saved-filters/{savedFilter}', [SavedFilterController::class, 'destroy']);

    /*
    |----------------------------------------------------------------------
    | Clients / conversation memory
    |----------------------------------------------------------------------
    */
    Route::get('/clients/{client}', [ClientController::class, 'show']);
    Route::post('/clients/{client}/draft-reply', [ClientController::class, 'draftReply']);

    /*
    |----------------------------------------------------------------------
    | Settings. GET is settings.view (a bidder can see rules), but the
    | controller OMITS every secret field entirely for anyone without
    | settings.edit_secrets — absent from the body, not masked in it.
    | Writes and secret-specific endpoints require the tighter permissions.
    |----------------------------------------------------------------------
    */
    Route::middleware('permission:settings.view')->group(function () {
        Route::get('/settings', [SettingsController::class, 'index']);
        Route::get('/diagnostics', DiagnosticsController::class);
    });

    Route::middleware('permission:settings.edit_rules')->group(function () {
        // store() enforces per-key: a rule key needs edit_rules, a secret key
        // needs edit_secrets, checked inside the controller.
        Route::post('/settings', [SettingsController::class, 'store']);
        Route::post('/settings/logo', [SettingsController::class, 'uploadLogo']);
        Route::delete('/settings/logo', [SettingsController::class, 'removeLogo']);
    });

    Route::middleware('permission:settings.edit_secrets')->group(function () {
        Route::get('/settings/agent-token', [SettingsController::class, 'revealAgentToken']);
        Route::post('/settings/agent-token/regenerate', [SettingsController::class, 'regenerateAgentToken']);
        Route::post('/settings/test/{service}', [SettingsController::class, 'testConnection']);
        Route::get('/ai-usage', \App\Http\Controllers\AiUsageController::class);
    });

    Route::middleware('permission:analytics.view')->group(function () {
        Route::get('/analytics', [AnalyticsController::class, 'index']);
    });

    /*
    |----------------------------------------------------------------------
    | Members — invite, list, change role, remove. Requires members.invite
    | to even see the tab; individual actions re-check their own permission.
    |----------------------------------------------------------------------
    */
    Route::middleware('permission:members.invite')->group(function () {
        Route::get('/members', [\App\Http\Controllers\MemberController::class, 'index']);
        Route::post('/members/invite', [\App\Http\Controllers\MemberController::class, 'invite']);
        Route::post('/members/{invitation}/resend', [\App\Http\Controllers\MemberController::class, 'resend']);
    });
    Route::put('/members/{user}/role', [\App\Http\Controllers\MemberController::class, 'changeRole'])
        ->middleware('permission:members.assign_role');
    Route::delete('/members/{user}', [\App\Http\Controllers\MemberController::class, 'remove'])
        ->middleware('permission:members.remove');

    // The read-only Roles matrix — any member who can see Settings can see
    // what each role can do.
    Route::get('/roles-matrix', [\App\Http\Controllers\MemberController::class, 'rolesMatrix'])
        ->middleware('permission:settings.view');
});
