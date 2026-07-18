<?php

namespace Tests\Feature\Leads;

use App\Enums\LeadStatus;
use App\Jobs\ScoreLeadJob;
use App\Models\Lead;
use App\Services\Ai\ProposalService;
use App\Services\Ai\ScoringService as AiScoringService;
use App\Services\ScoringService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScoreLeadJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->setMany([
            'openclaw_url' => 'https://openclaw.test',
            'openclaw_token' => 'token',
            'bidder_whatsapp' => '+15550001111',
            'score_cutoff' => 7,
            'max_proposals' => 25,
            'min_budget' => 150,
            'anthropic_api_key' => 'sk-ant-test',
            'scoring_system_prompt' => 'THE RUBRIC: score jobs 1-10, output JSON.',
            'proposal_system_prompt' => 'THE GUIDE: write the proposal, plain text.',
        ]);
    }

    protected function runJob(Lead $lead): void
    {
        (new ScoreLeadJob($lead->id))->handle(
            app(ScoringService::class),
            app(AiScoringService::class),
            app(ProposalService::class),
            app(SettingsService::class),
        );
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

    public function test_hard_filter_archives_lead_without_any_ai_call(): void
    {
        Http::fake();

        $lead = Lead::factory()->create(['proposal_count' => 999, 'status' => LeadStatus::New]);

        $this->runJob($lead);

        $lead->refresh();
        $this->assertEquals(LeadStatus::Archived, $lead->status);
        $this->assertStringContainsString('max_proposals', (string) $lead->score_reason);

        Http::assertNothingSent();
    }

    public function test_stale_posting_archives_lead_without_any_ai_call(): void
    {
        // Visible in the dashboard as Archived, never deleted — this only
        // saves the AI call on a job that's realistically already gone.
        Http::fake();

        $lead = Lead::factory()->create([
            'proposal_count' => 2,
            'budget' => '$500 fixed',
            'status' => LeadStatus::New,
            'posted_at' => now()->subDays(10),
        ]);

        $this->runJob($lead);

        $lead->refresh();
        $this->assertEquals(LeadStatus::Archived, $lead->status);
        $this->assertStringContainsString('max_posted_age_days', (string) $lead->score_reason);

        Http::assertNothingSent();
    }

    public function test_red_flag_word_archives_lead_without_any_ai_call(): void
    {
        Http::fake();
        app(SettingsService::class)->set('red_flag_words', ['unpaid sample']);

        $lead = Lead::factory()->create([
            'proposal_count' => 2,
            'budget' => '$500 fixed',
            'client_spend' => '$5.4K',
            'full_brief' => 'Please complete an unpaid sample task first.',
            'status' => LeadStatus::New,
            'posted_at' => now(),
        ]);

        $this->runJob($lead);

        $lead->refresh();
        $this->assertEquals(LeadStatus::Archived, $lead->status);
        $this->assertStringContainsString('red-flag', (string) $lead->score_reason);
        Http::assertNothingSent();
    }

    public function test_bid_yes_scores_writes_proposal_and_notifies_bidder(): void
    {
        // Scoring then proposal hit the Anthropic API sequentially; the
        // WhatsApp alert still goes out through OpenClaw.
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->anthropic('{"score": 9, "bid": true, "boost": true, "reason": "Great fit"}'))
                ->push($this->anthropic('Quick one about your Postgres schema...')),
            'openclaw.test/*' => Http::response(['success' => true]),
        ]);

        $lead = Lead::factory()->create(['proposal_count' => 2, 'budget' => '$500 fixed', 'status' => LeadStatus::New, 'posted_at' => now()]);

        $this->runJob($lead);

        $lead->refresh();
        $this->assertEquals(LeadStatus::Ready, $lead->status);
        $this->assertEquals(9, $lead->score);
        $this->assertTrue($lead->boost);
        $this->assertEquals('Quick one about your Postgres schema...', $lead->proposal_text);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return ($data['skill'] ?? null) === 'send_whatsapp_message'
                && $data['to'] === '+15550001111'
                && str_contains($data['message'], 'Score: 9/10')
                && str_contains($data['message'], 'BID: Yes | BOOST: Yes')
                && str_contains($data['message'], '📝 PROPOSAL:')
                && str_contains($data['message'], 'Quick one about your Postgres schema...');
        });
    }

    public function test_bid_no_archives_lead_with_no_proposal_call_and_no_whatsapp(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->anthropic('{"score": 3, "bid": false, "boost": false, "reason": "Weak fit"}')),
        ]);

        $lead = Lead::factory()->create(['proposal_count' => 2, 'budget' => '$500 fixed', 'status' => LeadStatus::New, 'posted_at' => now()]);

        $this->runJob($lead);

        $lead->refresh();
        $this->assertEquals(LeadStatus::Archived, $lead->status);
        $this->assertEquals(3, $lead->score);
        $this->assertNull($lead->proposal_text);

        // Exactly one AI call — the ~40% saving is the skipped proposal.
        Http::assertSentCount(1);
    }

    public function test_scoring_uses_the_settings_rubric_as_cached_system_prompt(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->anthropic('{"score": 2, "bid": false, "reason": "no"}')),
        ]);

        $lead = Lead::factory()->create(['proposal_count' => 2, 'budget' => '$500 fixed', 'status' => LeadStatus::New, 'posted_at' => now()]);

        $this->runJob($lead);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains((string) $request->url(), 'api.anthropic.com')
                && ($body['system'][0]['text'] ?? null) === 'THE RUBRIC: score jobs 1-10, output JSON.'
                && ($body['system'][0]['cache_control']['type'] ?? null) === 'ephemeral';
        });
    }

    public function test_ai_engine_disabled_skips_ai_call_and_leaves_lead_new(): void
    {
        Http::fake();
        app(SettingsService::class)->set('ai_engine_enabled', false);

        $lead = Lead::factory()->create(['proposal_count' => 2, 'budget' => '$500 fixed', 'status' => LeadStatus::New, 'posted_at' => now()]);

        $this->runJob($lead);

        $lead->refresh();
        $this->assertEquals(LeadStatus::New, $lead->status);
        $this->assertNull($lead->score);

        Http::assertNothingSent();
    }

    public function test_final_failure_marks_lead_needs_review_not_archived(): void
    {
        // A lead that never got a real evaluation shouldn't read as
        // "reviewed and rejected" (archived) — needs_review keeps it
        // visible on the board, eligible for a manual rescore, and the
        // operator gets a WhatsApp alert about the unscored lead.
        Http::fake(['*' => Http::response(['success' => true])]);
        $lead = Lead::factory()->create(['status' => LeadStatus::Scoring]);

        (new ScoreLeadJob($lead->id))->failed(new \RuntimeException('Provider timed out'));

        $lead->refresh();
        $this->assertEquals(LeadStatus::NeedsReview, $lead->status);
        $this->assertStringContainsString('Provider timed out', (string) $lead->score_reason);

        Http::assertSent(fn ($request) => ($request['skill'] ?? null) === 'send_whatsapp_message'
            && str_contains($request['message'], 'NOT scored'));
    }

    public function test_inline_attempt_failure_skips_final_failure_treatment(): void
    {
        // The inline (webhook-request) attempt's failure must not mark
        // needs_review or alert — the queued retry the importer dispatches
        // owns the real retry cycle and its final-failure handling.
        Http::fake();
        $lead = Lead::factory()->create(['status' => LeadStatus::Scoring]);

        (new ScoreLeadJob($lead->id, inline: true))->failed(new \RuntimeException('Provider timed out'));

        $lead->refresh();
        $this->assertEquals(LeadStatus::Scoring, $lead->status);
        Http::assertNothingSent();
    }
}
