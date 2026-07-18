<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_calls', function (Blueprint $table) {
            $table->id();
            $table->string('purpose'); // scoring | proposal | test
            $table->foreignId('lead_id')->nullable();
            $table->string('provider');
            $table->string('model');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            // Tokens served from the provider's prompt cache (billed ~0.1x
            // on Anthropic, 0.5x on OpenAI).
            $table->unsignedInteger('cached_tokens')->default(0);
            // Anthropic-only: tokens written to cache this call (billed 1.25x).
            $table->unsignedInteger('cache_write_tokens')->default(0);
            // Computed from the API's actual usage figures and a per-model
            // price map — never an estimate. Null when the model's pricing
            // isn't known (e.g. a custom OpenAI model ID).
            $table->decimal('cost_usd', 10, 6)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->boolean('success')->default(true);
            $table->text('error')->nullable();
            $table->timestamp('created_at');

            $table->index(['created_at']);
            $table->index(['lead_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_calls');
    }
};
