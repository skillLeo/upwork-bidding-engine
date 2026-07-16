<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // `client_hire_rate` stays the free-text display string (e.g.
            // "80%") - this is the numeric twin, parsed out the same way
            // budget_min/budget_max were, so "hire rate above 50%" can be a
            // real WHERE clause instead of fragile string matching.
            $table->decimal('client_hire_rate_pct', 5, 1)->nullable()->after('client_hire_rate');
            // Vollna's webhook already sends a distinct budget_type
            // (fixed/hourly) - VollnaProjectImporter previously only baked
            // it into the `budget` display string ("/hr" suffix) and threw
            // the discrete signal away. Store it so "fixed price jobs" can
            // filter on it directly.
            $table->string('budget_type')->nullable()->after('budget_max');

            $table->index('client_hire_rate_pct');
            $table->index('budget_type');
        });

        // Backfill existing rows from the display strings already stored -
        // the only source left for leads imported before this migration.
        DB::table('leads')->select('id', 'client_hire_rate', 'budget')->orderBy('id')
            ->chunkById(200, function ($leads) {
                foreach ($leads as $lead) {
                    $update = [];

                    if ($lead->client_hire_rate && preg_match('/[\d.]+/', $lead->client_hire_rate, $m)) {
                        $update['client_hire_rate_pct'] = (float) $m[0];
                    }

                    if ($lead->budget) {
                        if (stripos($lead->budget, '/hr') !== false) {
                            $update['budget_type'] = 'hourly';
                        } elseif (stripos($lead->budget, 'fixed') !== false) {
                            $update['budget_type'] = 'fixed';
                        }
                    }

                    if ($update !== []) {
                        DB::table('leads')->where('id', $lead->id)->update($update);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['client_hire_rate_pct', 'budget_type']);
        });
    }
};
