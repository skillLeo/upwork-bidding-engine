<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The in-app notification centre (the bell). Deliberately named
 * app_notifications, NOT the Laravel-convention `notifications` table, to
 * avoid any collision with Illuminate's own database-channel notifications.
 *
 * Account-wide with a single read state: this is effectively a one-operator
 * tool, so a notification read on the phone reads as read on the laptop too -
 * which is the behaviour the operator actually wants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);          // lead | reminder | system
            $table->string('level', 16)->default('info'); // info | success | warning
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('url')->nullable();   // where a click navigates
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
