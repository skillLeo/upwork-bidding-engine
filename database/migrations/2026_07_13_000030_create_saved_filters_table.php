<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_filters', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Single bidder tool, not multi-tenant - filters are shared account-wide,
            // same as Settings, rather than owned per-user.
            $table->boolean('is_default')->default(false);
            $table->boolean('is_pinned')->default(false);
            $table->json('criteria');
            $table->timestamps();

            $table->index('is_default');
            $table->index('is_pinned');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_filters');
    }
};
