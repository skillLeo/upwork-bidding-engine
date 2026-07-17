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

    public function test_stale_posting_archives_lead_without_calling_openclaw(): void
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
            'posted_at' => now(),
        ]);

        $this->runJob($lead);

        $lead->refresh();
        $this->assertEquals(LeadStatus::Archived, $lead->status);
        $this->assertStringContainsString('red-flag', (string) $lead->score_reason);
        Http::assertNothingSent();
    }

    public function test_score_at_or_above_cutoff_marks_lead_ready_and_notifies_bidder(): void
    {
        // NotifyBidderJob is dispatched sync (real-time WhatsApp alert), so
        // it isn't queueable to intercept here — assert the real outbound
        // WhatsApp call instead.
        Http::fake([
            'openclaw.test/*' => Http::response(['score' => 9, 'reason' => 'Great fit', 'proposal' => 'Hi there...']),
        ]);

        $lead = Lead::factory()->create(['proposal_count' => 2, 'budget' => '$500 fixed', 'status' => LeadStatus::New, 'posted_at' => now()]);

        $this->runJob($lead);

        $lead->refresh();
        $this->assertEquals(LeadStatus::Ready, $lead->status);
        $this->assertEquals(9, $lead->score);
        $this->assertEquals('Hi there...', $lead->proposal_text);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return ($data['skill'] ?? null) === 'send_whatsapp_message'
                && $data['to'] === '+15550001111'
                && str_contains($data['message'], 'Score: 9/10')
                && str_contains($data['message'], 'BID: Yes | BOOST: Yes')
                && str_contains($data['message'], '📝 PROPOSAL:')
                && str_contains($data['message'], 'Hi there...');
        });
    }

    public function test_score_below_cutoff_archives_lead_without_notifying(): void
    {
        Http::fake([
            'openclaw.test/*' => Http::response(['score' => 3, 'reason' => 'Weak fit', 'proposal' => '']),
        ]);

        $lead = Lead::factory()->create(['proposal_count' => 2, 'budget' => '$500 fixed', 'status' => LeadStatus::New, 'posted_at' => now()]);

        $this->runJob($lead);

        $lead->refresh();
        $this->assertEquals(LeadStatus::Archived, $lead->status);
        $this->assertEquals(3, $lead->score);

        Http::assertNotSent(fn ($request) => ($request->data()['skill'] ?? null) === 'send_whatsapp_message');
    }

    public function test_openclaw_request_carries_no_anthropic_credentials(): void
    {
        // OpenClaw is authenticated to Claude on its own (CLI subscription
        // auth) — this app must never send an API key/model, only the job
        // and rules.
        Http::fake([
            'openclaw.test/*' => Http::response(['score' => 8, 'reason' => 'ok', 'proposal' => 'p']),
        ]);

        $lead = Lead::factory()->create(['proposal_count' => 2, 'budget' => '$500 fixed', 'status' => LeadStatus::New, 'posted_at' => now()]);

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

        (new ScoreLeadJob($lead->id))->failed(new \RuntimeException('OpenClaw timed out'));

        $lead->refresh();
        $this->assertEquals(LeadStatus::NeedsReview, $lead->status);
        $this->assertStringContainsString('OpenClaw timed out', (string) $lead->score_reason);

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

        (new ScoreLeadJob($lead->id, inline: true))->failed(new \RuntimeException('OpenClaw timed out'));

        $lead->refresh();
        $this->assertEquals(LeadStatus::Scoring, $lead->status);
        Http::assertNothingSent();
    }
}
