<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\User;
use App\Services\ProposalVersionRecorder;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProposalAiEditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->setMany([
            'anthropic_api_key' => 'sk-ant-test',
            'proposal_skill' => 'SKILL RULES v2',
        ]);
    }

    private function fakeAnthropic(string $text): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => $text]],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 500, 'output_tokens' => 60, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
            ]),
        ]);
    }

    private function bidder(): User
    {
        return User::factory()->bidder()->create();
    }

    public function test_selection_edit_previews_the_spliced_result_and_persists_nothing(): void
    {
        $this->fakeAnthropic('{"replacement":"the improved sentence"}');
        $lead = Lead::factory()->ready()->create(['proposal_text' => 'Hello world here.']);

        $data = $this->actingAs($this->bidder(), 'sanctum')
            ->postJson("/api/leads/{$lead->id}/proposal/ai-edit", [
                'instruction' => 'make it sharper',
                'selection_start' => 6, // "world"
                'selection_end' => 11,
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame('ai_surgical_edit', $data['edit_type']);
        $this->assertSame('Hello the improved sentence here.', $data['new_text']);
        $this->assertSame(0, $lead->proposalVersions()->count());
    }

    public function test_whole_edit_previews_a_full_revision(): void
    {
        $this->fakeAnthropic('A fully revised proposal body.');
        $lead = Lead::factory()->ready()->create(['proposal_text' => 'Old proposal.']);

        $data = $this->actingAs($this->bidder(), 'sanctum')
            ->postJson("/api/leads/{$lead->id}/proposal/ai-edit", ['instruction' => 'tighten it'])
            ->assertOk()
            ->json('data');

        $this->assertSame('ai_instructed_rewrite', $data['edit_type']);
        $this->assertSame('A fully revised proposal body.', $data['new_text']);
        $this->assertArrayHasKey('linter_violations', $data);
        $this->assertSame(0, $lead->proposalVersions()->count());
    }

    public function test_bad_json_on_a_selection_edit_returns_422_and_writes_nothing(): void
    {
        $this->fakeAnthropic('sorry, I cannot do that'); // not JSON
        $lead = Lead::factory()->ready()->create(['proposal_text' => 'Hello world here.']);

        $this->actingAs($this->bidder(), 'sanctum')
            ->postJson("/api/leads/{$lead->id}/proposal/ai-edit", [
                'instruction' => 'x',
                'selection_start' => 0,
                'selection_end' => 5,
            ])
            ->assertStatus(422);

        $this->assertSame(0, $lead->proposalVersions()->count());
    }

    public function test_safeguards_run_on_the_preview_result(): void
    {
        // A whole edit that returns a banned em dash must be flagged in the
        // preview, before the operator can accept it.
        $this->fakeAnthropic('This proposal has an em dash — right here in the body.');
        $lead = Lead::factory()->ready()->create(['proposal_text' => 'Old.']);

        $data = $this->actingAs($this->bidder(), 'sanctum')
            ->postJson("/api/leads/{$lead->id}/proposal/ai-edit", ['instruction' => 'whatever'])
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($data['linter_violations']);
    }

    public function test_accept_persists_the_previewed_text_as_a_new_version(): void
    {
        $lead = Lead::factory()->ready()->create(['proposal_text' => 'Old.']);

        $this->actingAs($this->bidder(), 'sanctum')
            ->postJson("/api/leads/{$lead->id}/proposal/ai-edit/accept", [
                'proposal_text' => 'New accepted proposal body.',
                'edit_type' => 'ai_instructed_rewrite',
                'instruction' => 'tighten it',
                'model' => 'claude-sonnet-5',
            ])
            ->assertOk();

        $lead->refresh();
        $this->assertSame('New accepted proposal body.', $lead->proposal_text);
        $this->assertDatabaseHas('proposal_versions', [
            'lead_id' => $lead->id,
            'edit_type' => 'ai_instructed_rewrite',
            'edit_instruction' => 'tighten it',
            'model' => 'claude-sonnet-5',
        ]);
    }

    public function test_accept_rejects_an_unknown_edit_type(): void
    {
        $lead = Lead::factory()->ready()->create(['proposal_text' => 'Old.']);

        $this->actingAs($this->bidder(), 'sanctum')
            ->postJson("/api/leads/{$lead->id}/proposal/ai-edit/accept", [
                'proposal_text' => 'x',
                'edit_type' => 'manual_edit', // not an AI edit type
            ])
            ->assertStatus(422);
    }

    public function test_both_ai_edit_endpoints_refuse_once_the_proposal_is_sent(): void
    {
        $lead = Lead::factory()->ready()->create(['proposal_text' => 'Body.']);
        $recorder = app(ProposalVersionRecorder::class);
        $recorder->record($lead, 'Body.', 'initial_draft');
        $recorder->markLatestSent($lead);

        $this->actingAs($this->bidder(), 'sanctum')
            ->postJson("/api/leads/{$lead->id}/proposal/ai-edit", ['instruction' => 'change it'])
            ->assertStatus(422);

        $this->actingAs($this->bidder(), 'sanctum')
            ->postJson("/api/leads/{$lead->id}/proposal/ai-edit/accept", [
                'proposal_text' => 'sneaky change',
                'edit_type' => 'ai_instructed_rewrite',
            ])
            ->assertStatus(422);

        // Only the sent version exists; nothing new was written.
        $this->assertSame(1, $lead->proposalVersions()->count());
    }
}
