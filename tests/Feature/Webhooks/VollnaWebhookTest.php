<?php

namespace Tests\Feature\Webhooks;

use App\Enums\LeadStatus;
use App\Jobs\ScoreLeadJob;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VollnaWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->setMany([
            'vollna_webhook_secret' => 'test-secret',
            // Inline scoring now runs against the AI provider directly on
            // the webhook request, driven by the settings-held rubric.
            'anthropic_api_key' => 'sk-ant-test',
            'scoring_system_prompt' => 'THE RUBRIC',
            'proposal_skill' => 'THE GUIDE',
            // The quality gate (draft → review → revise) has its own test
            // file; here it would only add nondeterministic extra calls.
            'proposal_quality_gate' => false,
            'openclaw_url' => 'https://openclaw.test',
            'openclaw_token' => 'token',
            'bidder_whatsapp' => '+15550001111',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function batch(array ...$projects): array
    {
        return [
            'total' => count($projects),
            'results_url' => 'https://www.vollna.com/dashboard/monitoring/result/1',
            'filter' => ['id' => 1, 'name' => 'Backend Development'],
            'filters' => [['id' => 1, 'name' => 'Backend Development']],
            'projects' => $projects,
        ];
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

    public function test_rejects_request_without_a_secret_header(): void
    {
        $this->postJson('/api/vollna-hook', $this->batch(['title' => 'Job', 'url' => '...?pid=1']))
            ->assertStatus(401);

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_rejects_request_with_wrong_secret(): void
    {
        $this->postJson(
            '/api/vollna-hook',
            $this->batch(['title' => 'Job', 'url' => '...?pid=1']),
            ['X-Vollna-Secret' => 'wrong-secret'],
        )->assertStatus(401);

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_accepts_bearer_token_auth(): void
    {
        // Vollna's own webhook UI only offers None / Bearer Token / Basic Auth -
        // Bearer is the primary real-world path, X-Vollna-Secret is a fallback.
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->anthropic('{"score": 3, "bid": false, "reason": "weak"}')),
        ]);

        $response = $this->postJson(
            '/api/vollna-hook',
            $this->batch(['title' => 'Bearer Auth Job', 'url' => 'https://www.vollna.com/go?pid=bearer-job-1']),
            ['Authorization' => 'Bearer test-secret'],
        );

        $response->assertStatus(201)->assertJsonPath('data.accepted', 1);
        $this->assertDatabaseHas('leads', ['external_id' => 'vollna_pid_bearer-job-1']);
    }

    public function test_rejects_wrong_bearer_token(): void
    {
        $this->postJson(
            '/api/vollna-hook',
            $this->batch(['title' => 'Job', 'url' => '...?pid=1']),
            ['Authorization' => 'Bearer wrong-secret'],
        )->assertStatus(401);
    }

    public function test_accepts_valid_payload_creates_lead_and_scores_it_inline(): void
    {
        // Stage 2 so the model's boost verdict flows through to the DB —
        // stage 1 (the default) force-disables boost, covered in
        // ScoringStageTest.
        app(SettingsService::class)->set('account_stage', 'stage_2_established');

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->anthropic('{"score": 9, "bid": true, "boost": true, "reason": "Great fit"}'))
                ->push($this->anthropic('Hi there...')),
            'openclaw.test/*' => Http::response(['success' => true]),
        ]);

        $response = $this->postJson('/api/vollna-hook', $this->batch([
            'url' => 'https://www.vollna.com/go?module=webhook&pid=3359&url=https%3A%2F%2Fwww.upwork.com%2Fjobs%2F~01',
            'title' => 'Laravel Developer Needed',
            'description' => 'Build an API.',
            'budget_type' => 'fixed',
            'budget' => '500 USD',
            // Dynamic: a hardcoded date silently aged past the 7-day
            // staleness filter and started archiving this fixture.
            'published' => now()->subDay()->toIso8601String(),
            'client_details' => [
                'country' => ['name' => 'United States', 'iso_code2' => 'US'],
                'total_spent' => 12500,
                'payment_method_verified' => true,
            ],
            'connects_required' => 6,
        ]), ['X-Vollna-Secret' => 'test-secret']);

        $response->assertStatus(201)->assertJsonPath('data.accepted', 1);

        // No queue/cron involved: by the time the webhook responds, the
        // lead is already scored (by the rubric), proposal written, and
        // past `new`/`scoring`.
        $this->assertDatabaseHas('leads', [
            'external_id' => 'vollna_pid_3359',
            'title' => 'Laravel Developer Needed',
            'status' => LeadStatus::Ready->value,
            'score' => 9,
            'boost' => true,
            'proposal_text' => 'Hi there...',
            'payment_verified' => true,
            'client_country' => 'United States',
            'connects_required' => 6,
        ]);

        // The WhatsApp alert still routes through OpenClaw.
        Http::assertSent(fn ($request) => ($request->data()['skill'] ?? null) === 'send_whatsapp_message');
    }

    public function test_batch_with_multiple_projects_creates_a_lead_per_project(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->anthropic('{"score": 3, "bid": false, "reason": "weak"}')),
        ]);

        $response = $this->postJson('/api/vollna-hook', $this->batch(
            ['title' => 'Laravel Job One', 'url' => 'https://www.vollna.com/go?pid=101'],
            ['title' => 'Laravel Job Two', 'url' => 'https://www.vollna.com/go?pid=102'],
        ), ['X-Vollna-Secret' => 'test-secret']);

        $response->assertStatus(201)
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.accepted', 2);

        $this->assertDatabaseCount('leads', 2);
        // One scoring call per lead — bid:no means no proposal call and no
        // health checks (the OpenClaw gate is gone).
        Http::assertSentCount(2);
    }

    public function test_duplicate_external_id_is_idempotent_and_does_not_rescore(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->anthropic('{"score": 3, "bid": false, "reason": "weak"}')),
        ]);

        // Names a core stack so the cheap stack gate lets it reach the model
        // — this test is about idempotency, not about what gets scored.
        $payload = $this->batch(['title' => 'Laravel Job One', 'url' => 'https://www.vollna.com/go?pid=dup-1']);
        $headers = ['X-Vollna-Secret' => 'test-secret'];

        $this->postJson('/api/vollna-hook', $payload, $headers)->assertStatus(201);
        $this->postJson('/api/vollna-hook', $payload, $headers)
            ->assertStatus(200)
            ->assertJsonPath('data.duplicate', 1);

        $this->assertDatabaseCount('leads', 1);
        // One scoring call for the first delivery; the duplicate makes none.
        Http::assertSentCount(1);
    }

    public function test_inline_scoring_failure_still_saves_lead_and_queues_retry(): void
    {
        // Whatever breaks the inline attempt (provider down, key revoked),
        // the lead must still save, the webhook must still 201 fast, and a
        // real queued retry must exist — no lead is ever lost.
        Queue::fake();
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'overloaded'], 529)]);

        $response = $this->postJson('/api/vollna-hook', $this->batch([
            'title' => 'Provider Down Job',
            'url' => 'https://www.vollna.com/go?pid=down-1',
            'budget' => '500 USD',
            'budget_type' => 'fixed',
            'published' => now()->toIso8601String(),
        ]), ['X-Vollna-Secret' => 'test-secret']);

        $response->assertStatus(201)->assertJsonPath('data.accepted', 1);

        $this->assertDatabaseHas('leads', [
            'external_id' => 'vollna_pid_down-1',
            'score' => null,
        ]);

        Queue::assertPushed(ScoreLeadJob::class);
    }

    public function test_missing_title_is_rejected(): void
    {
        $this->postJson(
            '/api/vollna-hook',
            $this->batch(['url' => 'https://www.vollna.com/go?pid=no-title']),
            ['X-Vollna-Secret' => 'test-secret'],
        )->assertStatus(422);
    }
}
