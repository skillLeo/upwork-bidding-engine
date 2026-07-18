<?php

namespace Tests\Feature\Leads;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RegenerateScoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->setMany([
            'anthropic_api_key' => 'sk-ant-test',
            'scoring_system_prompt' => 'THE RUBRIC: score jobs 1-10, output JSON.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function anthropic(string $text): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 500, 'output_tokens' => 60, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
        ];
    }

    public function test_unscored_new_lead_gets_status_from_the_verdict(): void
    {
        // The recovery path: a lead wiped by the old queued rescore (or one
        // the dead queue never processed) sits in `new` with no score — one
        // click must score it AND land it in the right list.
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->anthropic(
                '{"score": 8, "bid": true, "reason": "strong", "sub_scores": {"client_quality": 8, "competition": 9, "stack_fit": 8, "budget": 7, "post_quality": 8}}',
            )),
        ]);

        $lead = Lead::factory()->create(['status' => LeadStatus::New, 'score' => null, 'score_reason' => null]);

        $this->actingAs(User::factory()->bidder()->create(), 'sanctum')
            ->postJson("/api/leads/{$lead->id}/regenerate-score")
            ->assertOk()
            ->assertJsonPath('data.score', 8)
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.sub_scores.competition', 9);

        $this->assertEquals(LeadStatus::Ready, $lead->fresh()->status);
    }

    public function test_bid_no_verdict_archives_an_intake_stage_lead(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->anthropic('{"score": 3, "bid": false, "reason": "weak"}')),
        ]);

        $lead = Lead::factory()->create(['status' => LeadStatus::NeedsReview]);

        $this->actingAs(User::factory()->bidder()->create(), 'sanctum')
            ->postJson("/api/leads/{$lead->id}/regenerate-score")
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');
    }

    public function test_worked_lead_keeps_its_status_on_rescore(): void
    {
        // Re-scoring a lead that's already sent must never demote it.
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->anthropic('{"score": 4, "bid": false, "reason": "cooled off"}')),
        ]);

        $lead = Lead::factory()->create(['status' => LeadStatus::Sent, 'score' => 8]);

        $this->actingAs(User::factory()->bidder()->create(), 'sanctum')
            ->postJson("/api/leads/{$lead->id}/regenerate-score")
            ->assertOk()
            ->assertJsonPath('data.score', 4)
            ->assertJsonPath('data.status', 'sent');
    }
}
