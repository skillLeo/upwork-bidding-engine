<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6: authenticator-app TOTP, alongside the existing email-OTP columns
 * (two_factor_enabled/_code/_challenge/_expires_at, added in P3 — that is
 * NOT this). A user can hold both; login prefers TOTP when confirmed.
 *
 * google2fa_secret is encrypted via an Eloquent cast (not this app's usual
 * SettingsService Crypt pattern, since this lives on the User model, not the
 * settings table) — never stored or logged in the clear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('google2fa_secret')->nullable()->after('two_factor_attempts');
            // JSON array of Hash::make()'d 8-char codes; consumed one at a
            // time by removing the matched entry, never re-issued individually.
            $table->text('two_factor_recovery_codes')->nullable()->after('google2fa_secret');
            // Set only after the FIRST valid code is verified during
            // enrolment — "activate on display alone" never sets this.
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['google2fa_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
