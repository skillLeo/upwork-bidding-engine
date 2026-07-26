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

/*
|----------------------------------------------------------------------
| WhatsApp Business Cloud API (Meta) — inbound.
|
| Necessarily public: Meta holds no credential of ours. The GET handshake
| is gated on hub.verify_token matching the workspace's own, and every POST
| is authenticated by an X-Hub-Signature-256 HMAC over the raw body before
| a single field is acted on. This is what makes BID/SKIP/PAUSE replies
| possible at all — the OpenClaw path can only send, never receive.
|----------------------------------------------------------------------
*/
Route::get('/webhooks/whatsapp', [\App\Http\Controllers\WhatsAppWebhookController::class, 'verify'])
    ->middleware('throttle:webhooks');
Route::post('/webhooks/whatsapp', [\App\Http\Controllers\WhatsAppWebhookController::class, 'handle'])
    ->middleware('throttle:webhooks');

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
// P6: authenticator-app / recovery-code second step — same tight per-challenge
// limit as email OTP (a 6-8 char code is guessable fast without one).
Route::post('/auth/verify-totp', [AuthController::class, 'verifyTotp'])
    ->middleware('throttle:otp');
// P7: public self-serve sign-up. The controller re-checks signup_mode=open
// (off by default) and refuses otherwise, so this is inert until an admin
// opens registration. Same rate limit as login.
Route::post('/auth/register', [AuthController::class, 'register'])
    ->middleware('throttle:login');
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:password-reset');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:login');

/*
|----------------------------------------------------------------------
| Google OAuth (P6). redirect/callback are real browser navigations
| (Google itself redirects here), never axios calls. link confirms a
| matching-email account with its PASSWORD before creating the link —
| see SocialAuthController's docblock for why that step is not optional.
|----------------------------------------------------------------------
*/
Route::get('/auth/google/redirect', [\App\Http\Controllers\SocialAuthController::class, 'redirect'])
    ->middleware('throttle:login');
Route::get('/auth/google/callback', [\App\Http\Controllers\SocialAuthController::class, 'callback'])
    ->middleware('throttle:login');
Route::post('/auth/google/link', [\App\Http\Controllers\SocialAuthController::class, 'link'])
    ->middleware('throttle:otp');
// Exchanges the opaque ?handoff= code the callback redirect carries for the
// real payload (token, 2FA challenge, or link prompt) — see
// SocialAuthController's class docblock for why the URL never carries that
// payload directly.
Route::post('/auth/google/exchange', [\App\Http\Controllers\SocialAuthController::class, 'exchange'])
    ->middleware('throttle:otp');

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

// P5: an impersonation token acts as the tenant user across the WHOLE app,
// so the write-block/auto-expiry has to sit ahead of every route below, not
// just /platform. A normal (non-impersonating) token passes straight
// through at negligible cost. enforce.2fa is P6's require_2fa enrolment
// lock — same "applies everywhere, cheap no-op when off" shape.
Route::middleware(['auth:sanctum', 'impersonation.guard', 'enforce.2fa'])->group(function () {
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

    // Authenticator-app TOTP (P6) — enrol, confirm (the one place
    // two_factor_confirmed_at is ever set), regenerate recovery codes, disable.
    Route::post('/profile/totp/enroll', [\App\Http\Controllers\TotpController::class, 'enroll']);
    Route::post('/profile/totp/confirm', [\App\Http\Controllers\TotpController::class, 'confirm']);
    Route::post('/profile/totp/recovery-codes/regenerate', [\App\Http\Controllers\TotpController::class, 'regenerateRecoveryCodes']);
    Route::post('/profile/totp/disable', [\App\Http\Controllers\TotpController::class, 'disable']);

    // Connected accounts (P6) — Google today.
    Route::get('/profile/social-accounts', [ProfileController::class, 'socialAccounts']);
    Route::delete('/profile/social-accounts/{provider}', [ProfileController::class, 'unlinkSocialAccount']);

    // Devices and sessions — always scoped to the caller's own account.
    Route::get('/profile/sessions', [SessionController::class, 'index']);
    Route::delete('/profile/sessions/others', [SessionController::class, 'destroyOthers']);
    Route::delete('/profile/sessions/{token}', [SessionController::class, 'destroy']);

    // Notification preferences — which events email/push this person, quiet
    // hours (P5 Profile extension).
    Route::get('/profile/notification-preferences', [ProfileController::class, 'notificationPreferences']);
    Route::put('/profile/notification-preferences', [ProfileController::class, 'updateNotificationPreferences']);

    // My workspaces — list every workspace this account belongs to, switch
    // (a navigation link, tenancy is subdomain-resolved), leave.
    Route::get('/profile/workspaces', [ProfileController::class, 'workspaces']);
    Route::delete('/profile/workspaces/{tenant}', [ProfileController::class, 'leaveWorkspace']);

    // Ending an impersonation session — reachable WHILE impersonating (see
    // ImpersonationGuard's route-name exemption) since it's how that state
    // ends. Not gated by platform.staff: the caller here is the
    // IMPERSONATED tenant user's token, not a platform-staff account.
    Route::post('/platform/impersonate/end', [\App\Http\Controllers\Platform\ImpersonationController::class, 'end'])
        ->name('platform.impersonation.end');

    /*
    |----------------------------------------------------------------------
    | Workspace — name/slug, plan/status, member count, export, transfer
    | ownership, delete. Transfer/delete are hardcoded to owner_user_id
    | (see WorkspaceController's docblock), not gated by workspace.manage.
    |----------------------------------------------------------------------
    */
    Route::middleware('permission:workspace.manage')->group(function () {
        Route::get('/workspace', [\App\Http\Controllers\WorkspaceController::class, 'show']);
        Route::put('/workspace', [\App\Http\Controllers\WorkspaceController::class, 'update']);
        Route::get('/workspace/export', [\App\Http\Controllers\WorkspaceController::class, 'exportData']);
    });
    Route::post('/workspace/transfer-ownership', [\App\Http\Controllers\WorkspaceController::class, 'transferOwnership']);
    Route::delete('/workspace', [\App\Http\Controllers\WorkspaceController::class, 'destroy']);

    /*
    |----------------------------------------------------------------------
    | Leads — readable by admin + bidder, status updates by either
    | (bidder is the primary actor; admin retains full access), rescore
    | is admin-only (it re-spends the AI call).
    |----------------------------------------------------------------------
    */
    Route::get('/leads', [LeadController::class, 'index'])->middleware('permission:leads.view');
    Route::post('/leads/bulk-status', [LeadController::class, 'bulkStatus'])->middleware('permission:leads.bulk');
    Route::post('/leads/bulk-favorite', [LeadController::class, 'bulkFavorite'])->middleware('permission:leads.bulk');
    Route::get('/leads/{lead}', [LeadController::class, 'show'])->middleware('permission:leads.view');
    Route::post('/leads/{lead}/status', [LeadController::class, 'updateStatus']);
    Route::put('/leads/{lead}/proposal', [LeadController::class, 'updateProposal']);
    Route::get('/leads/{lead}/proposal-versions', [LeadController::class, 'proposalVersions'])->middleware('permission:proposals.versions_view');
    Route::post('/leads/{lead}/proposal/ai-edit', [LeadController::class, 'aiEditProposal']);
    Route::post('/leads/{lead}/proposal/ai-edit/accept', [LeadController::class, 'acceptAiEditProposal']);
    Route::post('/leads/{lead}/regenerate-score', [LeadController::class, 'regenerateScore'])->middleware('permission:leads.rescore');
    Route::post('/leads/{lead}/regenerate-proposal', [LeadController::class, 'regenerateProposal']);
    Route::post('/leads/{lead}/favorite', [LeadController::class, 'toggleFavorite'])->middleware('permission:leads.favorite');
    Route::post('/leads/{lead}/client-view', [LeadController::class, 'updateClientView'])->middleware('permission:leads.set_client_view');
    Route::post('/leads/{lead}/outcome', [LeadController::class, 'updateOutcome'])->middleware('permission:leads.set_outcome');
    // rescore also authorizes via LeadPolicy; sync now has its own permission
    // rather than piggybacking on leads.delete.
    Route::post('/leads/{lead}/rescore', [LeadController::class, 'rescore'])->middleware('permission:leads.rescore');
    Route::post('/leads/sync-vollna', [LeadController::class, 'syncVollna'])->middleware('permission:leads.sync_vollna');

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
    Route::middleware('permission:notifications.view')->group(function () {
        Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'markAllRead']);
        Route::post('/notifications/{notification}/read', [\App\Http\Controllers\NotificationController::class, 'markRead']);
    });

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
    Route::get('/clients/{client}', [ClientController::class, 'show'])->middleware('permission:clients.view');
    Route::post('/clients/{client}/draft-reply', [ClientController::class, 'draftReply'])->middleware('permission:clients.ai_draft_reply');

    /*
    |----------------------------------------------------------------------
    | Settings — ULTRA-GRANULAR. GET needs only settings.view; the payload
    | omits every secret key the caller doesn't hold setting.{key} for.
    | Writes are checked PER KEY inside store(): posting a key without its
    | own setting.{key} permission is a 403 naming the key.
    |----------------------------------------------------------------------
    */
    Route::middleware('permission:settings.view')->group(function () {
        Route::get('/settings', [SettingsController::class, 'index']);
        Route::post('/settings', [SettingsController::class, 'store']);
    });

    Route::get('/diagnostics', DiagnosticsController::class)
        ->middleware('permission:diagnostics.view');

    Route::middleware('permission:setting.app_logo_path')->group(function () {
        Route::post('/settings/logo', [SettingsController::class, 'uploadLogo']);
        Route::delete('/settings/logo', [SettingsController::class, 'removeLogo']);
    });

    // The agent token is one specific credential, so its reveal/regenerate
    // hang off that key's own permission.
    Route::middleware('permission:setting.agent_api_token')->group(function () {
        Route::get('/settings/agent-token', [SettingsController::class, 'revealAgentToken']);
        Route::post('/settings/agent-token/regenerate', [SettingsController::class, 'regenerateAgentToken']);
    });

    Route::post('/settings/test/{service}', [SettingsController::class, 'testConnection'])
        ->middleware('permission:settings.test_connections');

    Route::get('/ai-usage', \App\Http\Controllers\AiUsageController::class)
        ->middleware('permission:ai_usage.view');

    Route::middleware('permission:analytics.view')->group(function () {
        Route::get('/analytics', [AnalyticsController::class, 'index']);
    });

    /*
    |----------------------------------------------------------------------
    | Permission editing — roles' grants and per-user overrides. Gated by
    | permissions.edit, itself a permission the owner can hand out or
    | revoke. The owner role and the owner user are untouchable regardless.
    |----------------------------------------------------------------------
    */
    Route::middleware('permission:permissions.edit')->group(function () {
        Route::put('/roles/{role}/permissions', [\App\Http\Controllers\PermissionEditorController::class, 'updateRole']);
        Route::get('/members/{user}/overrides', [\App\Http\Controllers\PermissionEditorController::class, 'showOverrides']);
        Route::put('/members/{user}/overrides', [\App\Http\Controllers\PermissionEditorController::class, 'updateOverrides']);
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

    /*
    |----------------------------------------------------------------------
    | Platform console (P5) — a separate area, gated ONLY by platform_role
    | (platform.staff), a column no tenant-facing UI can ever write. Never
    | linked from tenant navigation. Impersonation START lives here (staff
    | only); impersonation END is above, outside this group, because the
    | caller at that point is the impersonated tenant user, not staff.
    |----------------------------------------------------------------------
    */
    Route::prefix('platform')->middleware('platform.staff')->group(function () {
        Route::get('/tenants', [\App\Http\Controllers\Platform\TenantController::class, 'index']);
        Route::get('/tenants/{tenant}', [\App\Http\Controllers\Platform\TenantController::class, 'show']);

        Route::post('/tenants/{tenant}/users/{user}/impersonate', [\App\Http\Controllers\Platform\ImpersonationController::class, 'start']);

        Route::get('/settings', [\App\Http\Controllers\Platform\SettingsController::class, 'show']);
        Route::put('/settings', [\App\Http\Controllers\Platform\SettingsController::class, 'update']);

        Route::get('/health', \App\Http\Controllers\Platform\HealthController::class);
    });
});
