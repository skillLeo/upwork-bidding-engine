<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->string('budget_discussed')->nullable();
            $table->text('agreed_scope')->nullable();
            $table->enum('stage', ['new', 'talking', 'negotiating', 'closing', 'won', 'lost'])
                ->default('new');
            $table->longText('notes')->nullable();
            $table->timestamps();

            $table->index('stage');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
