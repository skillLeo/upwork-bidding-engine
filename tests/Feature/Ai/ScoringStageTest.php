<?php

namespace Tests\Feature\Ai;

use App\Enums\LeadStatus;
use App\Jobs\ScoreLeadJob;
use App\Models\Lead;
use App\Services\Ai\ScoringService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScoringStageTest extends TestCase
{
    use RefreshDatabase;

    protected SettingsService $settings;

    protected const RUBRIC = 'THE RUBRIC: score jobs 1-10, output JSON.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = app(SettingsService::class);
        $this->settings->set('anthropic_api_key', 'sk-ant-test');
        $this->settings->set('scoring_system_prompt', self::RUBRIC);
    }

    /**
     * @return array<string, mixed>
     */
    protected function anthropicResponse(string $text): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 500, 'output_tokens' => 60, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
        ];
    }

    public function test_stage_1_forces_boost_false_regardless_of_model_output(): void
    {
        // Default stage is stage_1_new — the model screaming boost:true
        // must not survive: the rubric's own Stage 1 advice is never
        // boost before the first reviews.
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->anthropicResponse(
                '{"score": 9, "bid": true, "boost": true, "reason": "hot", "sub_scores": {"client_quality": 9, "competition": 8, "stack_fit": 9, "budget": 7, "post_quality": 8}}',
            )),
        ]);

        $result = app(ScoringService::class)->score(Lead::factory()->create());

        $this->assertSame(9, $result['score']);
        $this->assertTrue($result['bid']);
        $this->assertFalse($result['boost']);
        $this->assertSame(
            ['client_quality' => 9, 'competition' => 8, 'stack_fit' => 9, 'budget' => 7, 'post_quality' => 8],
            $result['sub_scores'],
        );

        // Stage 1 sends the rubric + sub-scores spec and nothing else —
        // the stage 2 addendum must not leak in.
        Http::assertSent(function ($request) {
            $system = (string) ($request->data()['system'][0]['text'] ?? '');

            return str_contains($system, 'sub_scores') && ! str_contains($system, 'Account update:');
        });
    }

    public function test_stage_2_appends_addendum_and_lets_boost_through(): void
    {
        $this->settings->set('account_stage', 'stage_2_established');

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->anthropicResponse(
                '{"score": 9, "bid": true, "boost": true, "reason": "hot"}',
            )),
        ]);

        $result = app(ScoringService::class)->score(Lead::factory()->create());

        $this->assertTrue($result['boost']);

        Http::assertSent(function ($request) {
            return str_contains(
                (string) ($request->data()['system'][0]['text'] ?? ''),
                'Account update: SkillLeo now has reviews',
            );
        });
    }

    public function test_stage_difference_is_exactly_the_addendum_byte_for_byte(): void
    {
        $service = app(ScoringService::class);

        $stage1 = $service->systemPrompt();
        $this->assertSame(self::RUBRIC."\n\n".ScoringService::SUB_SCORES_SPEC, $stage1);

        $this->settings->set('account_stage', 'stage_2_established');
        $stage2 = app(ScoringService::class)->systemPrompt();

        $this->assertSame(
            $stage1."\n\n".trim((string) $this->settings->get('stage_2_scoring_addendum')),
            $stage2,
        );
    }

    public function test_missing_or_malformed_sub_scores_never_fail_scoring(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->anthropicResponse('{"score": 7, "bid": true, "reason": "no sub scores at all"}'))
                ->push($this->anthropicResponse('{"score": 7, "bid": true, "reason": "garbage subs", "sub_scores": {"client_quality": "high", "budget": 99, "bogus_key": 5}}')),
        ]);

        $service = app(ScoringService::class);

        $this->assertNull($service->score(Lead::factory()->create())['sub_scores']);

        // Non-numeric entries are dropped, numeric ones clamped to 1-10,
        // unknown keys ignored.
        $this->assertSame(['budget' => 10], $service->score(Lead::factory()->create())['sub_scores']);
    }

    public function test_score_lead_job_persists_sub_scores(): void
    {
        $this->settings->setMany([
            'min_budget' => 0,
            'max_posted_age_days' => 30,
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->anthropicResponse(
                '{"score": 3, "bid": false, "reason": "weak", "sub_scores": {"client_quality": 2, "competition": 4, "stack_fit": 3, "budget": 2, "post_quality": 4}}',
            )),
        ]);

        $lead = Lead::factory()->create(['status' => LeadStatus::New, 'budget' => '$500 fixed', 'posted_at' => now(), 'proposal_count' => 2]);
        ScoreLeadJob::dispatchSync($lead->id);

        $lead->refresh();
        $this->assertEquals(LeadStatus::Archived, $lead->status);
        $this->assertSame(
            ['client_quality' => 2, 'competition' => 4, 'stack_fit' => 3, 'budget' => 2, 'post_quality' => 4],
            $lead->sub_scores,
        );
    }
}
