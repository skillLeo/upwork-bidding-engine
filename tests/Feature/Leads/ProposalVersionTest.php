<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\ProposalVersion;
use App\Models\User;
use App\Services\ProposalVersionRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProposalVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_recorder_appends_sequential_versions_and_runs_the_linter(): void
    {
        $lead = Lead::factory()->ready()->create();
        $recorder = app(ProposalVersionRecorder::class);

        // An em dash is a banned phrase, so the linter must flag it - proving
        // the safeguards run on the version, not just on AI output.
        $v1 = $recorder->record($lead, 'Hello world — this has a banned dash.', 'manual_edit');
        $v2 = $recorder->record($lead, 'A clean second version with no dash.', 'manual_edit');

        $this->assertSame(1, $v1->version_number);
        $this->assertSame(2, $v2->version_number);
        $this->assertGreaterThan(0, $v1->linter_violation_count);
        $this->assertNotNull($v1->linter_violations);
        $this->assertSame(2, $lead->proposalVersions()->count());
    }

    public function test_manual_edit_endpoint_appends_a_version_and_re_lints_the_lead(): void
    {
        $bidder = User::factory()->bidder()->create();
        $lead = Lead::factory()->ready()->create();

        $response = $this->actingAs($bidder, 'sanctum')->putJson("/api/leads/{$lead->id}/proposal", [
            'proposal_text' => 'A by-hand rewrite that sneaks in an em dash — which is banned.',
        ]);

        $response->assertOk();
        $lead->refresh();

        $this->assertStringContainsString('em dash', $lead->proposal_text);
        $this->assertDatabaseHas('proposal_versions', [
            'lead_id' => $lead->id,
            'edit_type' => 'manual_edit',
            'version_number' => 1,
            'created_by' => $bidder->id,
        ]);
        // Re-lint result is surfaced on the lead the same way an AI write does.
        $this->assertNotEmpty($lead->proposal_warnings);
    }

    public function test_manual_edit_rejects_an_empty_body(): void
    {
        $bidder = User::factory()->bidder()->create();
        $lead = Lead::factory()->ready()->create();

        $this->actingAs($bidder, 'sanctum')
            ->putJson("/api/leads/{$lead->id}/proposal", ['proposal_text' => ''])
            ->assertStatus(422);

        $this->assertSame(0, $lead->proposalVersions()->count());
    }

    public function test_marking_a_lead_sent_freezes_the_latest_version(): void
    {
        $bidder = User::factory()->bidder()->create();
        $lead = Lead::factory()->ready()->create();
        app(ProposalVersionRecorder::class)->record($lead, 'The version that will be sent.', 'initial_draft');

        $this->actingAs($bidder, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/status", ['status' => 'sent'])
            ->assertOk();

        $version = $lead->proposalVersions()->first();
        $this->assertTrue($version->is_sent);
        $this->assertTrue($version->is_locked);
        $this->assertNotNull($version->sent_at);
    }

    public function test_marking_sent_with_no_versions_does_not_error(): void
    {
        $bidder = User::factory()->bidder()->create();
        $lead = Lead::factory()->ready()->create(); // proposal_text but no version rows

        $this->actingAs($bidder, 'sanctum')
            ->postJson("/api/leads/{$lead->id}/status", ['status' => 'sent'])
            ->assertOk();

        $this->assertSame(0, ProposalVersion::count());
    }

    public function test_versions_endpoint_lists_history_newest_first(): void
    {
        $bidder = User::factory()->bidder()->create();
        $lead = Lead::factory()->ready()->create();
        $recorder = app(ProposalVersionRecorder::class);
        $recorder->record($lead, 'First.', 'initial_draft');
        $recorder->record($lead, 'Second.', 'manual_edit');

        $response = $this->actingAs($bidder, 'sanctum')
            ->getJson("/api/leads/{$lead->id}/proposal-versions")
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertSame(2, $data[0]['version_number']);
        $this->assertSame(1, $data[1]['version_number']);
    }
}
