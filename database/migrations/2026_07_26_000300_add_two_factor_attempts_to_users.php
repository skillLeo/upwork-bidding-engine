<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-challenge attempt counter for the email OTP.
 *
 * A 6-digit code is only ~1,000,000 possibilities, and the IP rate limit
 * alone does not bound guesses against ONE challenge — an attacker with a
 * handful of addresses could grind it. Counting attempts against the
 * challenge itself, and destroying the challenge at 5, does.
 *
 * On the users table rather than a separate one because a challenge is
 * single-use and already lives here (two_factor_challenge / _code /
 * _expires_at); a second table would just be those columns again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('two_factor_attempts')->default(0)->after('two_factor_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_attempts');
        });
    }
};
