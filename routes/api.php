<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DiagnosticsController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SavedFilterController;
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

// Product name + logo — the sign-in screen needs these before anyone has
// a token, so this stays outside auth:sanctum entirely.
Route::get('/branding', [SettingsController::class, 'branding']);

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
    ->middleware('throttle:login');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    /*
    |----------------------------------------------------------------------
    | Profile — every authenticated user manages their own account, not
    | admin-gated like Settings.
    |----------------------------------------------------------------------
    */
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/profile/password', [ProfileController::class, 'updatePassword']);
    Route::post('/profile/avatar', [ProfileController::class, 'uploadAvatar']);
    Route::put('/profile/two-factor', [ProfileController::class, 'toggleTwoFactor']);

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
    Route::post('/leads/{lead}/favorite', [LeadController::class, 'toggleFavorite']);
    Route::post('/leads/{lead}/rescore', [LeadController::class, 'rescore'])->middleware('role:admin');
    Route::post('/leads/sync-vollna', [LeadController::class, 'syncVollna'])->middleware('role:admin');

    /*
    |----------------------------------------------------------------------
    | Saved lead filters — shared account-wide, same as Settings, rather
    | than owned per-user (this is a single-bidder tool, not multi-tenant).
    |----------------------------------------------------------------------
    */
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
    | Settings — admin only, enforced here AND hidden client-side.
    |----------------------------------------------------------------------
    */
    Route::middleware('role:admin')->group(function () {
        Route::get('/settings', [SettingsController::class, 'index']);
        Route::post('/settings', [SettingsController::class, 'store']);
        Route::post('/settings/test/{service}', [SettingsController::class, 'testConnection']);
        Route::post('/settings/logo', [SettingsController::class, 'uploadLogo']);
        Route::delete('/settings/logo', [SettingsController::class, 'removeLogo']);

        Route::get('/analytics', [AnalyticsController::class, 'index']);
        Route::get('/diagnostics', DiagnosticsController::class);
    });
});
