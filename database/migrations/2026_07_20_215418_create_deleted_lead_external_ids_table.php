<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Vollna's sync is check-then-insert against LIVE lead rows only
        // (see VollnaProjectImporter::importProject) - hard-deleting a lead
        // removes the only record of its external_id, so a job that
        // reappears in a later poll/mirror sync gets recreated as if new.
        // This is a permanent tombstone the importer checks before ever
        // inserting, independent of whether the Lead row itself still
        // exists.
        Schema::create('deleted_lead_external_ids', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deleted_lead_external_ids');
    }
};
