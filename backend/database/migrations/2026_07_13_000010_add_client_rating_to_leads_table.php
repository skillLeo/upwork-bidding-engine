<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->decimal('client_rating', 2, 1)->nullable()->after('client_hire_rate');
            $table->unsignedInteger('client_reviews')->nullable()->after('client_rating');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['client_rating', 'client_reviews']);
        });
    }
};
