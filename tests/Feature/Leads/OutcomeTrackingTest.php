<?php

namespace Tests\Feature\Leads;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutcomeTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_moving_to_sent_stamps_submitted_at_once(): void
    {
        $bidder = User::factory()->bidder()->create();
        $lead = Lead::factory()->ready()->create();

        $this->actingAs($bidder, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/status", ['status' => 'sent'])
            ->assertOk();

        $lead->refresh();
        $this->assertNotNull($lead->submitted_at);
        $first = $lead->submitted_at;

        // A later status change must never move the original submit time.
        $this->travel(2)->hours();
        $this->actingAs($bidder, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/status", ['status' => 'replied'])
            ->assertOk();

        $this->assertTrue($lead->fresh()->submitted_at->equalTo($first));
    }

    public function test_bulk_status_to_sent_also_stamps_submitted_at_once_per_lead(): void
    {
        $bidder = User::factory()->bidder()->create();
        $alreadySent = Lead::factory()->ready()->create(['status' => 'sent', 'submitted_at' => now()->subDays(3)]);
        $fresh = Lead::factory()->ready()->create();
        $originalStamp = $alreadySent->submitted_at;

        $this->actingAs($bidder, 'sanctum')
            ->postJson('/api/leads/bulk-status', ['ids' => [$alreadySent->id, $fresh->id], 'status' => 'sent'])
            ->assertOk();

        $this->assertTrue($alreadySent->fresh()->submitted_at->equalTo($originalStamp), 'must not overwrite an existing submitted_at');
        $this->assertNotNull($fresh->fresh()->submitted_at);
    }

    public function test_toggle_viewed_flips_and_unflips(): void
    {
        $bidder = User::factory()->bidder()->create();
        $lead = Lead::factory()->sent()->create();

        $this->actingAs($bidder, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/viewed")
            ->assertOk()
            ->assertJsonPath('data.viewed_at', fn ($v) => $v !== null);

        $this->actingAs($bidder, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/viewed")
            ->assertOk()
            ->assertJsonPath('data.viewed_at', null);
    }

    public function test_outcome_is_set_and_can_be_cleared_independent_of_status(): void
    {
        $bidder = User::factory()->bidder()->create();
        $lead = Lead::factory()->sent()->create();

        $this->actingAs($bidder, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/outcome", ['outcome' => 'closed_no_hire'])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'closed_no_hire')
            ->assertJsonPath('data.status', 'sent'); // status untouched

        $this->assertNotNull($lead->fresh()->outcome_at);

        $this->actingAs($bidder, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/outcome", ['outcome' => null])
            ->assertOk()
            ->assertJsonPath('data.outcome', null);

        $this->assertNull($lead->fresh()->outcome_at);
    }

    public function test_outcome_rejects_an_invalid_value(): void
    {
        $bidder = User::factory()->bidder()->create();
        $lead = Lead::factory()->sent()->create();

        $this->actingAs($bidder, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/outcome", ['outcome' => 'made_up_value'])
            ->assertStatus(422);
    }

    public function test_backfill_command_sources_only_from_a_real_activity_log_entry(): void
    {
        $withHistory = Lead::factory()->ready()->create();
        ActivityLog::record('lead_status_updated', subject: $withHistory, meta: ['from' => 'ready', 'to' => 'sent']);
        $withHistory->update(['status' => 'sent']);
        // Simulate the pre-migration state: no submitted_at despite being sent.
        Lead::whereKey($withHistory->id)->update(['submitted_at' => null]);

        $noHistory = Lead::factory()->ready()->create(['status' => 'sent']);
        Lead::whereKey($noHistory->id)->update(['submitted_at' => null]);

        $this->artisan('leads:backfill-submitted-at')
            ->expectsOutputToContain('Backfilled: 1. Left null')
            ->assertSuccessful();

        $this->assertNotNull($withHistory->fresh()->submitted_at);
        $this->assertNull($noHistory->fresh()->submitted_at, 'no real activity_log entry exists for this lead - must stay null, never fabricated');
    }
}
