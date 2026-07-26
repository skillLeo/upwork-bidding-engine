<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A workspace's specialization label — "Laravel + Vue", "Graphic design",
 * "DevOps" (P8).
 *
 * DISPLAY ONLY, and this is load-bearing enough to say twice. Nothing in
 * scoring, proposal writing, or lead filtering reads this column. What a
 * workspace is actually good at is expressed by its core/secondary/excluded
 * stack lists, which the scoring rubric and the proposal linter both render
 * from. A second, free-text place to say the same thing would inevitably
 * drift from the lists, and the one that quietly wins would be whichever the
 * code happened to read.
 *
 * It exists so the platform console's tenant list is readable at a glance and
 * so a workspace can name itself in its own words.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('specialization', 80)->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('specialization');
        });
    }
};
