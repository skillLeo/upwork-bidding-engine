<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\SavedFilterController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\VollnaWebhookController;
use App\Http\Controllers\WhatsAppWebhookController;
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

Route::get('/whatsapp-hook', [WhatsAppWebhookController::class, 'verify'])
    ->middleware('throttle:webhooks');
Route::post('/whatsapp-hook', [WhatsAppWebhookController::class, 'receive'])
    ->middleware('throttle:webhooks');

/*
|--------------------------------------------------------------------------
| Auth
|--------------------------------------------------------------------------
*/
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    /*
    |----------------------------------------------------------------------
    | Leads — readable by admin + bidder, status updates by either
    | (bidder is the primary actor; admin retains full access), rescore
    | is admin-only (it re-spends the AI call).
    |----------------------------------------------------------------------
    */
    Route::get('/leads', [LeadController::class, 'index']);
    Route::get('/leads/{lead}', [LeadController::class, 'show']);
    Route::post('/leads/{lead}/status', [LeadController::class, 'updateStatus']);
    Route::post('/leads/{lead}/rescore', [LeadController::class, 'rescore'])->middleware('role:admin');

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

        Route::get('/analytics', [AnalyticsController::class, 'index']);
    });
});
