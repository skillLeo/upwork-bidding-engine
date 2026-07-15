<?php

namespace Tests\Feature\Leads;

use App\Enums\LeadStatus;
use App\Jobs\NotifyBidderJob;
use App\Jobs\ScoreLeadJob;
use App\Models\Lead;
use App\Services\OpenClawService;
use App\Services\ScoringService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
            'score_cutoff' => 7,
            'max_proposals' => 25,
            'min_budget' => 150,
        ]);
    }

    protected function runJob(Lead $lead): void
    {
        (new ScoreLeadJob($lead->id))->handle(
            app(ScoringService::class),
            app(OpenClawService::class),
            app(SettingsService::class),
        );
    }

    public function test_hard_filter_archives_lead_without_calling_openclaw(): void
    {
        Http::fake();

        $lead = Lead::factory()->create(['proposal_count' => 999, 'status' => LeadStatus::New]);

        $this->runJob($lead);

        $lead->refresh();
        $this->assertEquals(LeadStatus::Archived, $lead->status);
        $this->assertStringContainsString('max_proposals', (string) $lead->score_reason);

        Http::assertNothingSent();
    }

    public function test_red_flag_word_archives_lead_without_calling_openclaw(): void
    {
        Http::fake();
        app(SettingsService::class)->set('red_flag_words', ['unpaid sample']);

        $lead = Lead::factory()->create([
            'proposal_count' => 2,
            'budget' => '$500 fixed',
            'client_spend' => '$5.4K',
            'full_brief' => 'Please complete an unpaid sample task first.',
            'status' => LeadStatus::New,
        ]);

        $this->runJob($lead);

        $lead->refresh();
        $this->assertEquals(LeadStatus::Archived, $lead->status);
        $this->assertStringContainsString('red-flag', (string) $lead->score_reason);
        Http::assertNothingSent();
    }

    public function test_score_at_or_above_cutoff_marks_lead_ready_and_notifies_bidder(): void
    {
        Queue::fake();
        Http::fake([
            'openclaw.test/*' => Http::response(['score' => 9, 'reason' => 'Great fit', 'proposal' => 'Hi there...']),
        ]);

        $lead = Lead::factory()->create(['proposal_count' => 2, 'budget' => '$500 fixed', 'status' => LeadStatus::New]);

        $this->runJob($lead);

        $lead->refresh();
        $this->assertEquals(LeadStatus::Ready, $lead->status);
        $this->assertEquals(9, $lead->score);
        $this->assertEquals('Hi there...', $lead->proposal_text);

        Queue::assertPushed(NotifyBidderJob::class, fn (NotifyBidderJob $job) => $job->leadId === $lead->id);
    }

    public function test_score_below_cutoff_archives_lead_without_notifying(): void
    {
        Queue::fake();
        Http::fake([
            'openclaw.test/*' => Http::response(['score' => 3, 'reason' => 'Weak fit', 'proposal' => '']),
        ]);

        $lead = Lead::factory()->create(['proposal_count' => 2, 'budget' => '$500 fixed', 'status' => LeadStatus::New]);

        $this->runJob($lead);

        $lead->refresh();
        $this->assertEquals(LeadStatus::Archived, $lead->status);
        $this->assertEquals(3, $lead->score);

        Queue::assertNotPushed(NotifyBidderJob::class);
    }

    public function test_openclaw_request_carries_no_anthropic_credentials(): void
    {
        // OpenClaw is authenticated to Claude on its own (CLI subscription
        // auth) — this app must never send an API key/model, only the job
        // and rules.
        Queue::fake();
        Http::fake([
            'openclaw.test/*' => Http::response(['score' => 8, 'reason' => 'ok', 'proposal' => 'p']),
        ]);

        $lead = Lead::factory()->create(['proposal_count' => 2, 'budget' => '$500 fixed', 'status' => LeadStatus::New]);

        $this->runJob($lead);

        Http::assertSent(function ($request) {
            return ! array_key_exists('ai', $request->data())
                && array_key_exists('job', $request->data())
                && array_key_exists('rules', $request->data());
        });
    }

    public function test_ai_engine_disabled_skips_openclaw_call_and_leaves_lead_new(): void
    {
        Http::fake();
        app(SettingsService::class)->set('ai_engine_enabled', false);

        $lead = Lead::factory()->create(['proposal_count' => 2, 'budget' => '$500 fixed', 'status' => LeadStatus::New]);

        $this->runJob($lead);

        $lead->refresh();
        $this->assertEquals(LeadStatus::New, $lead->status);
        $this->assertNull($lead->score);

        Http::assertNothingSent();
    }

    public function test_final_failure_returns_lead_to_new_not_archived(): void
    {
        // A lead that never got a real evaluation shouldn't read as
        // "reviewed and rejected" (archived) — it goes back to `new`,
        // visible on the board, eligible for a manual rescore.
        $lead = Lead::factory()->create(['status' => LeadStatus::Scoring]);

        (new ScoreLeadJob($lead->id))->failed(new \RuntimeException('OpenClaw timed out'));

        $lead->refresh();
        $this->assertEquals(LeadStatus::New, $lead->status);
        $this->assertStringContainsString('OpenClaw timed out', (string) $lead->score_reason);
    }
}
