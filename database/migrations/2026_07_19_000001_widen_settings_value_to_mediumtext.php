<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEXT caps at 64KB on MySQL and the proposal rules (guide + 2026
 * addendum + SKILL.md v2) already run ~72KB — MEDIUMTEXT (16MB) leaves
 * the operator free to grow the rulebooks without ever hitting this again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->mediumText('value')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->text('value')->nullable()->change();
        });
    }
};
