<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6 Google sign-up: a brand-new account created entirely via Google (no
 * invite, no existing email) has no password at all — not a random unused
 * one, genuinely none, so User::hasPassword() can refuse "unlink Google"
 * for exactly the accounts that would otherwise be locked out permanently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        // No down: a NULL password can't be safely backfilled to NOT NULL
        // without inventing a value, which would be worse than leaving it.
    }
};
