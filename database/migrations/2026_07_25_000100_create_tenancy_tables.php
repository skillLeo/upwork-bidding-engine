<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three tables tenancy is built on. Structure only — the rows that
 * migrate existing data land in 2026_07_25_000300.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Lowercase, used as the subdomain, so it must be unique.
            $table->string('slug')->unique();
            $table->string('plan')->default('trial');
            // A plain string rather than a DB enum: this app already had to
            // widen leads.status from enum to string once (see
            // 2026_07_19_170000) because altering a MySQL enum to add one
            // value is a table rebuild. Not repeating that.
            $table->string('status', 16)->default('trialing');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        // A user can belong to more than one workspace — an agency bidder
        // working for two clients is the obvious case.
        Schema::create('tenant_users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('joined_at')->nullable();

            $table->primary(['tenant_id', 'user_id']);
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role');
            // The hash only. The raw token exists once, in the email.
            $table->string('token_hash')->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('tenant_users');
        Schema::dropIfExists('tenants');
    }
};
