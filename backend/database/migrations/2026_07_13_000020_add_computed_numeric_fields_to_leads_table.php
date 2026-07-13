<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // `budget` and `client_spend` stay free-text display strings
            // (e.g. "15 - 25 USD/hr", "$1.2K") - these are parsed out of
            // them so filters can query numerically instead of doing
            // fragile string matching.
            $table->decimal('budget_min', 10, 2)->nullable()->after('budget');
            $table->decimal('budget_max', 10, 2)->nullable()->after('budget_min');
            $table->decimal('client_spend_amount', 12, 2)->nullable()->after('client_spend');

            $table->index('budget_min');
            $table->index('budget_max');
            $table->index('client_spend_amount');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['budget_min', 'budget_max', 'client_spend_amount']);
        });
    }
};
