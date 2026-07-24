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

    public function test_client_view_moves_between_all_three_states_and_back_to_null(): void
    {
        $bidder = User::factory()->bidder()->create();
        $lead = Lead::factory()->sent()->create();

        foreach (['not_viewed', 'viewed', 'shortlisted'] as $state) {
            $this->actingAs($bidder, 'sanctum')
                ->postJson("/api/leads/{$lead->id}/client-view", ['client_view' => $state])
                ->assertOk()
                ->assertJsonPath('data.client_view', $state);
        }

        // Clearing back to "not recorded" must stay possible — null is a real
        // state that keeps the lead out of the dying-proposals denominator.
        $this->actingAs($bidder, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/client-view", ['client_view' => null])
            ->assertOk()
            ->assertJsonPath('data.client_view', null);

        $this->assertNull($lead->fresh()->client_view);
    }

    public function test_client_view_rejects_an_invalid_value(): void
    {
        $bidder = User::factory()->bidder()->create();
        $lead = Lead::factory()->sent()->create();

        $this->actingAs($bidder, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/client-view", ['client_view' => 'peeked'])
            ->assertStatus(422);
    }

    /**
     * Nothing in LeadOutcome may duplicate a value `status` already carries,
     * so the two can never disagree (status=Won + outcome=hired_other used to
     * be accepted). Status words are not valid reasons.
     */
    public function test_a_reason_can_never_be_a_status_word(): void
    {
        $bidder = User::factory()->bidder()->create();
        $lead = Lead::factory()->lost()->create();

        foreach (['replied', 'hired_me', 'won', 'sent', 'lost', 'archived'] as $statusWord) {
            $this->actingAs($bidder, 'sanctum')
                ->postJson("/api/leads/{$lead->id}/outcome", ['outcome' => $statusWord])
                ->assertStatus(422);
        }
    }

    /**
     * The reason must match the branch the lead ended on. A Lost lead takes a
     * post-bid reason; a pre-bid reason on it is a 422 (and vice versa), so
     * status and reason cannot be made to contradict from either side.
     */
    public function test_a_reason_from_the_wrong_branch_is_rejected(): void
    {
        $bidder = User::factory()->bidder()->create();
        $lost = Lead::factory()->lost()->create();
        $archived = Lead::factory()->create(['status' => 'archived']);

        // pre-bid reason on a lost (bid) lead — nonsense
        $this->actingAs($bidder, 'sanctum')
            ->postJson("/api/leads/{$lost->id}/outcome", ['outcome' => 'no_connects'])
            ->assertStatus(422);

        // post-bid reason on an archived (never-bid) lead — nonsense
        $this->actingAs($bidder, 'sanctum')
            ->postJson("/api/leads/{$archived->id}/outcome", ['outcome' => 'no_response'])
            ->assertStatus(422);
    }

    public function test_a_live_lead_accepts_no_reason_at_all(): void
    {
        $bidder = User::factory()->bidder()->create();
        $sent = Lead::factory()->sent()->create();

        // Nothing to explain yet — only clearing to null is legal.
        $this->actingAs($bidder, 'sanctum')
            ->postJson("/api/leads/{$sent->id}/outcome", ['outcome' => 'no_response'])
            ->assertStatus(422);

        $this->actingAs($bidder, 'sanctum')
            ->postJson("/api/leads/{$sent->id}/outcome", ['outcome' => null])
            ->assertOk()
            ->assertJsonPath('data.outcome', null);
    }

    public function test_a_lost_lead_records_and_clears_its_reason_without_touching_status(): void
    {
        $bidder = User::factory()->bidder()->create();
        $lead = Lead::factory()->lost()->create();

        $this->actingAs($bidder, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/outcome", ['outcome' => 'no_response'])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'no_response')
            ->assertJsonPath('data.status', 'lost');

        $this->assertNotNull($lead->fresh()->outcome_at);

        $this->actingAs($bidder, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/outcome", ['outcome' => null])
            ->assertOk()
            ->assertJsonPath('data.outcome', null);

        $this->assertNull($lead->fresh()->outcome_at);
    }

    /**
     * Moving a lead off the branch its reason belongs to must clear the reason
     * rather than leave "no response" sitting on a won lead.
     */
    public function test_moving_off_a_branch_clears_a_now_invalid_reason(): void
    {
        $bidder = User::factory()->bidder()->create();
        $lead = Lead::factory()->lost()->create(['outcome' => 'no_response', 'outcome_at' => now()]);

        $this->actingAs($bidder, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/status", ['status' => 'won'])
            ->assertOk()
            ->assertJsonPath('data.status', 'won')
            ->assertJsonPath('data.outcome', null);

        $this->assertNull($lead->fresh()->outcome_at);
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
