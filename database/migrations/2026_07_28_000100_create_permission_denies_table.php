<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user permission DENIES.
 *
 * Spatie natively supports per-user GRANTS (direct permissions on the user,
 * team-scoped), but has no concept of "this user's role allows X, but deny
 * it for THEM specifically". This table is that concept: a row here beats
 * any role grant, enforced by a Gate::before hook (see AppServiceProvider).
 *
 * The one exception is the workspace owner, who is exempt from denies
 * entirely — the same lock that keeps the Owner role at full access, so a
 * workspace can always be repaired.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_denies', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('permission');
            $table->timestamp('created_at')->nullable();

            $table->primary(['tenant_id', 'user_id', 'permission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_denies');
    }
};
