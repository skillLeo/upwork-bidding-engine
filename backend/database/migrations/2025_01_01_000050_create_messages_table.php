<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->enum('direction', ['in', 'out']);
            $table->longText('text');
            $table->longText('drafted_reply')->nullable();
            $table->boolean('needs_hassam')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('needs_hassam');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
