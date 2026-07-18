<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // The rubric's own BOOST verdict (value >= $1k AND fresh AND
            // exact fit) — richer than the old score>=9 shorthand, which
            // stays only as a fallback for pre-rubric leads.
            $table->boolean('boost')->nullable()->after('score');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('boost');
        });
    }
};
