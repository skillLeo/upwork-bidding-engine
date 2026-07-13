<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->string('title');
            $table->longText('full_brief');
            $table->string('url')->nullable();
            $table->string('budget')->nullable();
            $table->string('client_country')->nullable();
            $table->string('client_spend')->nullable();
            $table->string('client_hire_rate')->nullable();
            $table->boolean('payment_verified')->default(false);
            $table->unsignedInteger('proposal_count')->default(0);
            $table->unsignedTinyInteger('score')->nullable();
            $table->text('score_reason')->nullable();
            $table->longText('proposal_text')->nullable();
            $table->enum('status', ['new', 'scoring', 'ready', 'sent', 'replied', 'won', 'archived'])
                ->default('new');
            // Column only here — the FK constraint is added once `clients` exists,
            // in 2025_01_01_000040_add_client_foreign_to_leads_table.php.
            $table->unsignedBigInteger('client_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('score');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
