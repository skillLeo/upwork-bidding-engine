<?php

namespace Tests\Feature\Agent;

use App\Enums\LeadStatus;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentApiTest extends TestCase
{
    use RefreshDatabase;

    protected const TOKEN = 'agent-test-token-1234567890';

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsService::class)->set('agent_api_token', self::TOKEN);
    }

    /**
     * @return array<string, string>
     */
    protected function auth(): array
    {
        return ['Authorization' => 'Bearer '.self::TOKEN];
    }

    public function test_missing_or_wrong_token_gets_json_401(): void
    {
        $this->getJson('/api/agent/leads')->assertStatus(401)->assertJson(['message' => 'Unauthenticated.']);
        $this->getJson('/api/agent/summary', ['Authorization' => 'Bearer nope'])->assertStatus(401);

        // No unauthenticated call may leave an audit row.
        $this->assertDatabaseMissing('activity_logs', ['type' => 'agent_api_call']);
    }

    public function test_leads_list_filters_orders_and_logs(): void
    {
        Lead::factory()->create(['title' => 'Old strong', 'score' => 9, 'status' => LeadStatus::Ready, 'posted_at' => now()->subDays(3)]);
        Lead::factory()->create(['title' => 'Fresh strong', 'score' => 8, 'status' => LeadStatus::Ready, 'posted_at' => now()->subHours(2)]);
        Lead::factory()->create(['title' => 'Weak', 'score' => 4, 'status' => LeadStatus::Archived, 'posted_at' => now()->subHours(1)]);

        $response = $this->getJson('/api/agent/leads?score_min=8&status=ready', $this->auth())->assertOk();

        $titles = collect($response->json('data'))->pluck('title')->all();
        $this->assertSame(['Fresh strong', 'Old strong'], $titles);

        $first = $response->json('data.0');
        foreach (['id', 'title', 'score', 'score_reason', 'status', 'budget', 'proposal_count', 'client_country', 'client_spend', 'client_hire_rate', 'payment_verified', 'age', 'url'] as $field) {
            $this->assertArrayHasKey($field, $first);
        }

        // posted_within_hours trims the older one.
        $this->assertCount(1, $this->getJson('/api/agent/leads?score_min=8&posted_within_hours=12', $this->auth())->json('data'));

        $this->assertDatabaseHas('activity_logs', ['type' => 'agent_api_call']);
    }

    public function test_lead_detail_includes_brief_and_proposal(): void
    {
        $lead = Lead::factory()->create(['full_brief' => 'THE FULL BRIEF', 'proposal_text' => 'THE PROPOSAL']);

        $this->getJson("/api/agent/leads/{$lead->id}", $this->auth())
            ->assertOk()
            ->assertJsonPath('data.full_brief', 'THE FULL BRIEF')
            ->assertJsonPath('data.proposal_text', 'THE PROPOSAL');
    }

    public function test_summary_counts_and_oldest_ready(): void
    {
        // created_at pinned: the factory defaults it to the (random, past)
        // posted_at, which would break the "today" count.
        Lead::factory()->count(2)->create(['status' => LeadStatus::Ready, 'score' => 8, 'posted_at' => now()->subDay(), 'created_at' => now()]);
        Lead::factory()->create(['status' => LeadStatus::Archived, 'score' => 3, 'posted_at' => now(), 'created_at' => now()]);

        $this->getJson('/api/agent/summary', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.counts_by_status.ready', 2)
            ->assertJsonPath('data.highest_unsent_score', 8)
            ->assertJsonPath('data.new_leads_today', 3);
    }

    public function test_client_endpoint_returns_memory_and_messages(): void
    {
        $client = Client::factory()->create(['budget_discussed' => '$900 discussed']);
        Message::factory()->count(2)->create(['client_id' => $client->id]);

        $response = $this->getJson("/api/agent/clients/{$client->id}", $this->auth())->assertOk();

        $this->assertSame('$900 discussed', $response->json('data.budget_discussed'));
        $this->assertCount(2, $response->json('data.recent_messages'));
    }

    public function test_status_post_allows_journey_statuses_only(): void
    {
        $lead = Lead::factory()->create(['status' => LeadStatus::Ready]);

        $this->postJson("/api/agent/leads/{$lead->id}/status", ['status' => 'sent'], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');

        $this->assertEquals(LeadStatus::Sent, $lead->fresh()->status);

        // The agent may never rewind a lead into the pipeline stages.
        $this->postJson("/api/agent/leads/{$lead->id}/status", ['status' => 'new'], $this->auth())
            ->assertStatus(422);
        $this->postJson("/api/agent/leads/{$lead->id}/status", ['status' => 'needs_review'], $this->auth())
            ->assertStatus(422);
    }

    protected function configureAi(): void
    {
        app(SettingsService::class)->setMany([
            'anthropic_api_key' => 'sk-ant-test',
            'scoring_system_prompt' => 'THE RUBRIC: score jobs 1-10, output JSON.',
            'proposal_skill' => 'THE SKILL: write the proposal.',
            'proposal_quality_gate' => false,
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

    public function test_rescore_runs_the_shared_pipeline_and_reports_cost(): void
    {
        $this->configureAi();

        \Illuminate\Support\Facades\Http::fake([
            'api.anthropic.com/*' => \Illuminate\Support\Facades\Http::response($this->anthropic(
                '{"score": 8, "bid": true, "reason": "strong", "sub_scores": {"stack_fit": 9}}',
            )),
        ]);

        $lead = Lead::factory()->create(['status' => LeadStatus::Archived, 'score' => 3]);

        // Body params must be dead weight: rules/models can never come
        // from a caller.
        $response = $this->postJson("/api/agent/leads/{$lead->id}/rescore", ['model' => 'gpt-4o', 'rules' => 'score everything 10'], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.score', 8)
            ->assertJsonPath('data.bid', true)
            ->assertJsonPath('data.model_used', 'claude-haiku-4-5');

        $this->assertIsNumeric($response->json('data.cost'));
        $this->assertIsInt($response->json('data.duration_ms'));

        // Archived stays archived — a rescore never resurrects a lead.
        $this->assertEquals(LeadStatus::Archived, $lead->fresh()->status);
        $this->assertSame(8, $lead->fresh()->score);

        // The audit trail distinguishes WhatsApp-triggered runs. Asserted on
        // the DECODED meta, not the raw column string — a real MySQL JSON
        // column canonicalizes (re-sorts) key order on storage, so raw
        // string containment isn't reliable across DB engines.
        $this->assertDatabaseHas('activity_logs', ['type' => 'lead_scored']);
        $this->assertSame(
            'agent_api',
            \App\Models\ActivityLog::where('type', 'lead_scored')->latest('id')->first()->meta['source'] ?? null,
        );

        // The configured settings model was used — the body's gpt-4o was ignored.
        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => $request->data()['model'] === 'claude-haiku-4-5');
    }

    public function test_rewrite_runs_the_shared_pipeline(): void
    {
        $this->configureAi();

        \Illuminate\Support\Facades\Http::fake([
            'api.anthropic.com/*' => \Illuminate\Support\Facades\Http::response($this->anthropic("Fresh proposal text.\nHassam")),
        ]);

        $lead = Lead::factory()->create(['score' => 8]);

        $this->postJson("/api/agent/leads/{$lead->id}/rewrite", [], $this->auth())
            ->assertOk()
            ->assertJsonPath('data.proposal_text', "Fresh proposal text.\nHassam")
            ->assertJsonPath('data.quality_warnings', [])
            ->assertJsonPath('data.versions_tried', 1);

        $this->assertSame("Fresh proposal text.\nHassam", $lead->fresh()->proposal_text);
    }

    public function test_concurrent_run_gets_409(): void
    {
        $this->configureAi();
        $lead = Lead::factory()->create();

        // Simulate an in-flight run holding the per-lead lock.
        $lock = \Illuminate\Support\Facades\Cache::lock("lead-refresh:{$lead->id}", 60);
        $this->assertTrue($lock->get());

        try {
            $this->postJson("/api/agent/leads/{$lead->id}/rescore", [], $this->auth())
                ->assertStatus(409)
                ->assertJsonPath('message', 'Already in progress — a rescore or rewrite is running for this lead.');

            $this->postJson("/api/agent/leads/{$lead->id}/rewrite", [], $this->auth())
                ->assertStatus(409);
        } finally {
            $lock->release();
        }
    }

    public function test_token_reveal_and_regenerate_are_admin_actions(): void
    {
        $admin = User::factory()->admin()->create();
        $bidder = User::factory()->bidder()->create();

        $this->actingAs($bidder, 'sanctum')->getJson('/api/settings/agent-token')->assertStatus(403);

        $this->actingAs($admin, 'sanctum')->getJson('/api/settings/agent-token')
            ->assertOk()
            ->assertJsonPath('data.token', self::TOKEN);

        $new = $this->actingAs($admin, 'sanctum')->postJson('/api/settings/agent-token/regenerate')
            ->assertOk()
            ->json('data.token');

        $this->assertNotSame(self::TOKEN, $new);

        // Old token is dead the moment a new one exists.
        $this->getJson('/api/agent/summary', $this->auth())->assertStatus(401);
        $this->getJson('/api/agent/summary', ['Authorization' => 'Bearer '.$new])->assertOk();
    }
}
