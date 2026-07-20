<?php

namespace Tests\Feature\Leads;

use App\Models\DeletedLeadExternalId;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneOldLeadsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_stale_leads_older_than_the_cutoff_and_tombstones_them(): void
    {
        $old = Lead::factory()->create([
            'external_id' => 'vollna_pid_old',
            'posted_at' => now()->subHours(5),
        ]);
        $recent = Lead::factory()->create([
            'external_id' => 'vollna_pid_recent',
            'posted_at' => now()->subMinutes(30),
        ]);

        $this->artisan('leads:prune', ['--hours' => 2])->assertSuccessful();

        $this->assertDatabaseMissing('leads', ['id' => $old->id]);
        $this->assertDatabaseHas('leads', ['id' => $recent->id]);
        $this->assertDatabaseHas('deleted_lead_external_ids', ['external_id' => 'vollna_pid_old']);
        $this->assertDatabaseMissing('deleted_lead_external_ids', ['external_id' => 'vollna_pid_recent']);
    }

    public function test_never_deletes_sent_replied_won_or_favorited_leads_regardless_of_age(): void
    {
        $sent = Lead::factory()->sent()->create(['posted_at' => now()->subDays(10)]);
        $replied = Lead::factory()->replied()->create(['posted_at' => now()->subDays(10)]);
        $won = Lead::factory()->won()->create(['posted_at' => now()->subDays(10)]);
        $favorited = Lead::factory()->create(['posted_at' => now()->subDays(10), 'is_favorite' => true]);

        $this->artisan('leads:prune', ['--hours' => 2])->assertSuccessful();

        $this->assertDatabaseHas('leads', ['id' => $sent->id]);
        $this->assertDatabaseHas('leads', ['id' => $replied->id]);
        $this->assertDatabaseHas('leads', ['id' => $won->id]);
        $this->assertDatabaseHas('leads', ['id' => $favorited->id]);
    }

    public function test_a_lead_already_tombstoned_does_not_break_the_prune(): void
    {
        DeletedLeadExternalId::create(['external_id' => 'vollna_pid_dupe']);
        Lead::factory()->create([
            'external_id' => 'vollna_pid_dupe',
            'posted_at' => now()->subHours(5),
        ]);

        $this->artisan('leads:prune', ['--hours' => 2])->assertSuccessful();

        $this->assertSame(1, DeletedLeadExternalId::where('external_id', 'vollna_pid_dupe')->count());
    }
}
