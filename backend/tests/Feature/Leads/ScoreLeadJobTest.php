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

    public function test_openclaw_request_includes_claude_credentials_from_settings(): void
    {
        Queue::fake();
        app(SettingsService::class)->setMany([
            'claude_api_key' => 'sk-ant-secret',
            'claude_model' => 'claude-sonnet-4-6',
        ]);

        Http::fake([
            'openclaw.test/*' => Http::response(['score' => 8, 'reason' => 'ok', 'proposal' => 'p']),
        ]);

        $lead = Lead::factory()->create(['proposal_count' => 2, 'budget' => '$500 fixed', 'status' => LeadStatus::New]);

        $this->runJob($lead);

        Http::assertSent(function ($request) {
            return $request['ai']['api_key'] === 'sk-ant-secret'
                && $request['ai']['model'] === 'claude-sonnet-4-6';
        });
    }
}
